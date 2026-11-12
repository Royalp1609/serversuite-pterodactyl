<?php

namespace ServerSuite\Contracts;

use ServerSuite\Support\Data\BackupRef;

/**
 * Abstraction over the panel's server-operations API.
 *
 * Sister seam to DaemonFileClient: where DaemonFileClient wraps the *Wings
 * daemon* file API, PanelServerOps wraps *panel-side* server services that run
 * inside the panel process — currently the backup lifecycle. Both panels
 * (Pterodactyl, Pelican) expose these operations under different class names,
 * so each gets one implementation selected by PanelCompatService. Everything
 * above this line (BackupRotationService, controllers, UI) depends only on the
 * contract and the BackupRef DTO — never on a panel model.
 *
 * Servers are identified by full UUID throughout, matching DaemonFileClient.
 * Implementations resolve that UUID to the panel's Server model internally.
 *
 * The version changer (later module) reuses createBackup() to backup-gate
 * downgrades, which is why these methods live in a shared seam rather than in
 * the rotation service.
 */
interface PanelServerOps
{
    /**
     * List a server's backups, newest activity first is NOT guaranteed — the
     * caller orders as needed (eviction sorts by completedAt ascending).
     *
     * Returns every backup row the panel has for the server, including locked,
     * failed, and in-progress ones; filtering is the policy engine's job.
     *
     * @return BackupRef[]
     *
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function listBackups(string $serverUuid): array;

    /**
     * Create a new backup for the server.
     *
     * Always created UNLOCKED (rotation must be able to evict it later). The
     * panel enforces its own backup_limit and rate throttle here; if the limit
     * is hit this throws BackupException with reason 'limit_reached', and if
     * the throttle is hit, reason 'throttled' — the rotation policy is
     * responsible for making room and pacing before calling this.
     *
     * @param  string        $serverUuid
     * @param  string|null   $name          Backup name; null lets the panel default it.
     * @param  string[]|null $ignoredFiles  Optional glob patterns to exclude.
     *
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function createBackup(
        string $serverUuid,
        ?string $name = null,
        ?array $ignoredFiles = null,
    ): BackupRef;

    /**
     * Delete a single backup, identified by its uuid and scoped to the server.
     *
     * Scoping is enforced (a uuid that doesn't belong to $serverUuid yields a
     * 'not_found' BackupException) so a caller can never delete another
     * server's backup by guessing a uuid. Locked backups are refused by the
     * panel and surface as reason 'locked'.
     *
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function deleteBackup(string $serverUuid, string $backupUuid): void;

    /**
     * Lock or unlock a single backup (pin/unpin "keep-forever"), scoped to the
     * server. Locked backups are protected from rotation eviction. Mirrors the
     * panel's own toggle, but takes an explicit boolean so the UI can express
     * lock/unlock intent directly rather than blind-flipping.
     *
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function setBackupLocked(string $serverUuid, string $backupUuid, bool $locked): void;

    /**
     * Rename a single backup, scoped to the server.
     *
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function renameBackup(string $serverUuid, string $backupUuid, string $name): void;

    /**
     * A short-lived signed download URL for a backup, scoped to the server.
     *
     * @param  object $user The authenticated panel user requesting the download.
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function downloadUrl(string $serverUuid, string $backupUuid, object $user): string;

    /**
     * Restore a backup over the server's files. Destructive: when $truncate is
     * true the existing files are wiped first. The server is flagged as
     * restoring and Wings performs the restore asynchronously.
     *
     * @param  object $user The authenticated user (needed to sign S3 URLs).
     * @throws \ServerSuite\Exceptions\BackupException
     */
    public function restoreBackup(string $serverUuid, string $backupUuid, bool $truncate, object $user): void;

    /**
     * The server's configured backup limit (Pterodactyl: Server->backup_limit).
     *
     * Returns null when the panel exposes no limit concept. A limit of 0 means
     * backups are disabled for the server — the policy engine must treat that
     * as "do not create". Kept in the seam so the rotation service never has to
     * touch a panel Server model.
     */
    public function serverBackupLimit(string $serverUuid): ?int;

    /**
     * Seconds until the panel's backup rate limit would let a new backup be
     * created (0 = now). Lets rotation check before it deletes a backup to
     * make room (#129). Best effort: 0 when the panel has no such limit.
     */
    public function backupThrottleWait(string $serverUuid): int;

    /**
     * Best-effort loader + Minecraft version for a server, used to stamp every
     * backup with the world's format version (so a restore can't be silently
     * applied across an incompatible version). Returns nulls when detection is
     * uncertain — naming falls back to the un-stamped form, never an error.
     *
     * @return array{loader: ?string, version: ?string}
     */
    public function detectVersion(string $serverUuid): array;

    // ---- Version switch primitives (Phase 2) ------------------------------

    /**
     * Write server variable VALUES by env name (e.g. MINECRAFT_VERSION,
     * BUILD_NUMBER). Only the given keys are touched; others are left intact.
     * Unknown env names (not on the egg) are ignored.
     *
     * @param array<string,string> $env
     */
    public function setServerVariables(string $serverUuid, array $env): void;

    /**
     * Reassign a server to a different egg (loader switch). Rewrites egg_id,
     * nest_id and the server's startup to the target egg's, then clears and
     * re-seeds server_variables for the TARGET egg's variables — using values
     * from $environment where a matching env name is provided, otherwise the
     * target variable's default. A reinstall must follow to run the new egg's
     * install script.
     *
     * @param array<string,string> $environment  target env name => value
     */
    public function changeEgg(string $serverUuid, int $eggId, array $environment): void;

    /** Set the server's Docker image (takes effect on next (re)install/boot). */
    public function setDockerImage(string $serverUuid, string $image): void;

    /** Trigger a server reinstall (re-runs the egg install script). */
    public function reinstall(string $serverUuid): void;

    /** Send a power signal: start | stop | restart | kill. */
    public function power(string $serverUuid, string $signal): void;

    /**
     * Best-effort runtime state for gating the switch.
     * @return array{power: ?string, install: ?string}
     *   power:   live Wings state (running|offline|starting|stopping) or null
     *   install: panel install status (null = installed/idle, "installing", etc.)
     */
    public function runtimeState(string $serverUuid): array;
}
