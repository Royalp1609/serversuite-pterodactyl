<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\BackupException;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Support\Data\RotationResult;

/**
 * The rolling-backup policy engine.
 *
 * One public entry point — rotate() — implements the create flow decided in
 * HANDOFF-backup-build.md, with the guards from DESIGN-backup-rotation.md:
 *
 *   - Native Pterodactyl HARD-FAILS at backup_limit (TooManyBackupsException),
 *     so when the server is physically at its limit we evict the oldest
 *     eligible backup BEFORE creating, never after.
 *   - Below the limit we create first, then trim one excess backup — this
 *     keeps at least one good backup at all times (no gap), and lets keep=1
 *     settle correctly.
 *   - Throttle awareness: the panel allows ~2 backup operations per 600s and
 *     DELETES COUNT. So a cycle performs AT MOST one create + one evict (two
 *     ops). A backlog (e.g. keep lowered) trims one-per-cycle until converged;
 *     the scheduler interval must be wider than the throttle window.
 *   - Eviction target is the OLDEST by completed_at (never name-guessing),
 *     filtered to is_locked=false / is_successful=true / completed.
 *   - The last successful backup is never evicted to make room.
 *
 * All panel contact goes through PanelServerOps; this class never touches a
 * panel model, so it is fully unit-testable against FakePanelServerOps.
 */
class BackupRotationService
{
    public function __construct(
        private PanelServerOps $ops,
        private BackupRetentionResolver $retention,
    ) {}

    /**
     * Manual, user-triggered backup ("Back Up" button). Reuses the rotation
     * engine (still respects the limit, evicts the oldest unlocked at the
     * limit, honours the throttle) but names the backup with the user's custom
     * name, or "Manual <timestamp>" when none is given — never "Auto".
     */
    public function backupNow(string $serverUuid, ?string $name = null): RotationResult
    {
        $clean = $name !== null ? trim($name) : '';

        return $clean !== ''
            ? $this->rotate($serverUuid, null, null, $clean)   // verbatim custom name
            : $this->rotate($serverUuid, null, 'Manual');      // "Manual <timestamp>"
    }

    /**
     * How many backups rotation keeps for a server: what the billing API and
     * the UI report. 0 when the panel forbids backups (rotation skips).
     */
    public function effectiveKeep(string $serverUuid, ?int $keepOverride = null): int
    {
        $limit = $this->ops->serverBackupLimit($serverUuid);

        return $limit !== null && $limit <= 0 ? 0 : $this->keepFor($serverUuid, $limit, $keepOverride);
    }

    /**
     * Retention equals the panel-set backup limit: panel admins control the
     * count via the server's backup_limit, and server admins cannot change
     * it. An explicit override (CLI --keep) is still honoured but can never
     * exceed the limit. The resolver is only a fallback for the unusual case
     * of a server with no limit configured at all.
     */
    private function keepFor(string $serverUuid, ?int $limit, ?int $keepOverride): int
    {
        if ($keepOverride !== null) {
            return $limit === null ? $keepOverride : min($keepOverride, $limit);
        }

        return $limit ?? $this->retention->resolve($serverUuid);
    }

    /**
     * Run one rotation cycle for a server: ensure room, create one rolling
     * backup, and trim at most one excess backup.
     *
     * @param  string      $serverUuid
     * @param  int|null    $keepOverride  Explicit keep-N; null resolves via settings.
     * @param  string|null $namePrefix    Backup name prefix; null uses config.
     * @param  string|null $nameOverride  Verbatim backup name; bypasses the prefix.
     */
    public function rotate(string $serverUuid, ?int $keepOverride = null, ?string $namePrefix = null, ?string $nameOverride = null): RotationResult
    {
        $limit = $this->ops->serverBackupLimit($serverUuid);

        // A limit of 0 means the panel forbids backups for this server.
        if ($limit !== null && $limit <= 0) {
            return RotationResult::skipped('backups_disabled');
        }

        $effectiveKeep = $this->keepFor($serverUuid, $limit, $keepOverride);

        if ($effectiveKeep < 1) {
            return RotationResult::skipped('keep_below_one');
        }

        $all = $this->ops->listBackups($serverUuid);

        // Never stack on a running backup (completed_at null = in progress).
        if ($this->hasInProgress($all)) {
            return RotationResult::skipped('backup_in_progress');
        }

        $successful = $this->successful($all);
        $eligible   = $this->eligibleOldestFirst($successful);
        $retained   = count($successful);
        $atLimit    = $limit !== null && $retained >= $limit;

        $evicted = [];

        // ---- Phase A: pre-create eviction ONLY when physically at the limit ----
        if ($atLimit) {
            if ($retained <= 1) {
                return RotationResult::skipped('would_evict_last_success');
            }

            $victim = $eligible[0] ?? null;
            if ($victim === null) {
                return RotationResult::skipped('no_evictable_at_limit');
            }

            $outcome = $this->tryEvict($serverUuid, $victim);
            if ($outcome instanceof RotationResult) {
                return $outcome; // throttled / failed — surfaced as-is
            }
            $evicted[] = $victim;
        }

        // ---- Phase B: create the rolling backup ----
        // Stamp the world's format version onto the name (version-first, so the
        // restore flow can parse it and refuse an incompatible restore). Falls
        // back to the un-stamped name when detection is uncertain.
        $base = $nameOverride ?? $this->makeName($namePrefix);
        $name = $this->stampVersion($serverUuid, $base);

        try {
            $created = $this->ops->createBackup($serverUuid, $name);
        } catch (BackupException $e) {
            return $this->handleCreateFailure($serverUuid, $name, $e, $evicted, $effectiveKeep);
        }
        $this->saveModList($serverUuid, $created, $all);

        // ---- Phase C: post-create trim, at most one, only if we didn't evict ----
        if (empty($evicted)) {
            $evicted = array_merge(
                $evicted,
                $this->trimOne($serverUuid, $effectiveKeep)
            );
        }

        return RotationResult::rotated($created, $evicted, $effectiveKeep);
    }

    // -------------------------------------------------------------------------
    // Create-failure backstop (race guard for the panel limit)
    // -------------------------------------------------------------------------

    private function handleCreateFailure(
        string $serverUuid,
        string $name,
        BackupException $e,
        array $evicted,
        int $effectiveKeep,
    ): RotationResult {
        if ($e->reason === 'throttled') {
            return RotationResult::skipped('throttled', evicted: $evicted);
        }

        if ($e->reason !== 'limit_reached') {
            return RotationResult::failed('create_failed', $e->getMessage(), $evicted);
        }

        // limit_reached: if we already evicted this cycle, don't blow the
        // throttle by evicting again — report and let the next cycle proceed.
        if (! empty($evicted)) {
            return RotationResult::failed('limit_reached_after_evict', $e->getMessage(), $evicted);
        }

        // Otherwise make room once and retry the create a single time.
        $successful = $this->successful($this->ops->listBackups($serverUuid));
        $eligible   = $this->eligibleOldestFirst($successful);
        $victim     = $eligible[0] ?? null;

        if ($victim === null || count($successful) <= 1) {
            return RotationResult::failed('limit_reached_no_room', $e->getMessage(), $evicted);
        }

        $outcome = $this->tryEvict($serverUuid, $victim);
        if ($outcome instanceof RotationResult) {
            return $outcome;
        }
        $evicted[] = $victim;

        try {
            $created = $this->ops->createBackup($serverUuid, $name);
        } catch (BackupException $e2) {
            return RotationResult::failed('limit_reached_retry', $e2->getMessage(), $evicted);
        }
        $this->saveModList($serverUuid, $created, $successful);

        return RotationResult::rotated($created, $evicted, $effectiveKeep);
    }

    // -------------------------------------------------------------------------
    // Trim one excess backup after a successful create (throttle budget = 1)
    // -------------------------------------------------------------------------

    /**
     * @return BackupRef[] the evicted backup(s) — zero or one.
     */
    private function trimOne(string $serverUuid, int $effectiveKeep): array
    {
        $successful = $this->successful($this->ops->listBackups($serverUuid));

        // The freshly-created backup is still in progress (completed_at null),
        // so it is NOT in $successful. Reserve a slot for it by trimming the
        // completed set down to effectiveKeep - 1; once the new one completes,
        // the total settles at effectiveKeep.
        $target = $effectiveKeep - 1;

        if (count($successful) <= $target || count($successful) <= 1) {
            return [];
        }

        $eligible = $this->eligibleOldestFirst($successful);
        $victim = $eligible[0] ?? null;
        if ($victim === null) {
            return []; // everything excess is locked — honour the locks
        }

        $outcome = $this->tryEvict($serverUuid, $victim);
        if ($outcome instanceof RotationResult) {
            // throttled or transient — leave the trim for next cycle; the
            // create already succeeded so this is not a failure.
            return [];
        }

        return [$victim];
    }

    // -------------------------------------------------------------------------
    // Eviction helper — returns null on success, or a RotationResult to bubble
    // -------------------------------------------------------------------------

    private function tryEvict(string $serverUuid, BackupRef $victim): ?RotationResult
    {
        try {
            $this->ops->deleteBackup($serverUuid, $victim->uuid);
            app(RestoreSyncService::class)->forget($victim->uuid);

            return null;
        } catch (BackupException $e) {
            return match ($e->reason) {
                'throttled' => RotationResult::skipped('throttled'),
                'locked'    => RotationResult::skipped('no_evictable_at_limit'),
                default     => RotationResult::failed('evict_failed', $e->getMessage()),
            };
        }
    }

    /**
     * Save the mod list with the new backup, so restoring it can put the
     * list back (#60), and drop lists whose backup is gone. Never fails the
     * backup.
     *
     * @param BackupRef[] $known the server's backups before this one
     */
    private function saveModList(string $serverUuid, BackupRef $created, array $known): void
    {
        try {
            $sync = app(RestoreSyncService::class);
            $sync->snapshot($serverUuid, $created->uuid);
            $sync->prune($serverUuid, array_merge([$created->uuid], array_map(fn (BackupRef $b) => $b->uuid, $known)));
        } catch (\Throwable) {
        }
    }

    // -------------------------------------------------------------------------
    // Backup-set helpers
    // -------------------------------------------------------------------------

    /** @param BackupRef[] $all */
    private function hasInProgress(array $all): bool
    {
        foreach ($all as $b) {
            if ($b->completedAt === null && ! $b->isSuccessful) {
                return true;
            }
        }

        return false;
    }

    /**
     * Completed, successful backups (the retained keep-set; locked + unlocked).
     *
     * @param  BackupRef[] $all
     * @return BackupRef[]
     */
    private function successful(array $all): array
    {
        return array_values(array_filter(
            $all,
            fn (BackupRef $b) => $b->isSuccessful && $b->completedAt !== null,
        ));
    }

    /**
     * Eligible-for-eviction backups, OLDEST first by completed_at.
     *
     * @param  BackupRef[] $successful
     * @return BackupRef[]
     */
    private function eligibleOldestFirst(array $successful): array
    {
        $eligible = array_values(array_filter(
            $successful,
            fn (BackupRef $b) => ! $b->isLocked,
        ));

        usort($eligible, function (BackupRef $a, BackupRef $b) {
            return strcmp((string) $a->completedAt, (string) $b->completedAt);
        });

        return $eligible;
    }

    private function makeName(?string $namePrefix): string
    {
        $prefix = $namePrefix
            ?? (string) config('serversuite.backups.name_prefix', 'Auto');

        // The timestamp in the NAME is shown in the configured backup timezone
        // (NULL/empty follows the panel app timezone). Stored backup timestamps
        // stay UTC — this only affects the human-readable label. A bad tz value
        // never breaks rotation: fall back to the default formatting.
        $tz = config('serversuite.backups.timezone');

        try {
            $stamp = ($tz === null || $tz === '')
                ? now()->format('Y-m-d H:i')
                : now()->timezone($tz)->format('Y-m-d H:i');
        } catch (\Throwable $e) {
            $stamp = now()->format('Y-m-d H:i');
        }

        return trim($prefix . ' ' . $stamp);
    }

    /**
     * Prepend the world's format version: "v<MC> <Loader> · <base>".
     * Example: "v1.21.4 Paper · Auto 2026-06-30 14:32".
     *
     * The version comes first in a fixed, parseable position (^v\d+\.\d+\.\d+)
     * so the restore flow can read it back and block a cross-version restore.
     * Detection is best-effort and never fatal: if no version is known, the
     * base name is returned unchanged (older un-stamped backups simply get no
     * restore guard — there's nothing to parse).
     */
    private function stampVersion(string $serverUuid, string $base): string
    {
        try {
            $detected = $this->ops->detectVersion($serverUuid);
        } catch (\Throwable $e) {
            return $base;
        }

        $version = $detected['version'] ?? null;
        if (! $version) {
            return $base;
        }

        $loader = $detected['loader'] ?? null;
        $head   = 'v' . $version . ($loader ? ' ' . ucfirst($loader) : '');

        return $head . ' · ' . $base;
    }
}
