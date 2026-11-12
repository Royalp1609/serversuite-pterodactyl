<?php

namespace ServerSuite\Tests\Fakes;

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\BackupException;
use ServerSuite\Support\Data\BackupRef;

/**
 * In-memory PanelServerOps, mirroring FakeDaemonFileClient. Lets the seam and
 * rotation policy be exercised with zero panel dependency.
 *
 * Models the panel behaviours the rotation policy must cope with:
 *   - createBackup appends an IN-PROGRESS backup (completed_at null,
 *     is_successful false) — exactly as InitiateBackupService returns one.
 *   - createBackup throws limit_reached when active (non-failed) backups are at
 *     the server limit — the TooManyBackupsException the policy must pre-empt.
 *   - deleteBackup throws locked for is_locked rows, not_found for unknown uuids.
 *   - optional throttleAfter simulates the 2-per-600s op throttle (creates and
 *     deletes both count).
 */
class FakePanelServerOps implements PanelServerOps
{
    /** @var array<string, BackupRef[]> keyed by server uuid */
    public array $backups = [];

    /** @var array<string, int|null> per-server limit */
    public array $limits = [];

    /** Throw 'throttled' once this many ops have been performed (null = never). */
    public ?int $throttleAfter = null;

    public int $opCount = 0;
    public int $seq = 0;

    /** Record of completed operations for assertions. */
    public array $log = [];

    /**
     * Seed existing backups for a server.
     *
     * @param array<int, array{uuid?:string,name?:string,bytes?:int,locked?:bool,successful?:bool,completed?:?string,created?:?string}> $specs
     */
    public function seed(string $serverUuid, array $specs): void
    {
        foreach ($specs as $s) {
            $this->backups[$serverUuid][] = new BackupRef(
                uuid:         $s['uuid'] ?? ('seed-' . (++$this->seq)),
                name:         $s['name'] ?? ('seed-' . $this->seq),
                bytes:        $s['bytes'] ?? 1000,
                isLocked:     $s['locked'] ?? false,
                isSuccessful: $s['successful'] ?? true,
                createdAt:    $s['created'] ?? ($s['completed'] ?? null),
                completedAt:  $s['completed'] ?? null,
                id:           $this->seq,
            );
        }
    }

    public function setLimit(string $serverUuid, ?int $limit): void
    {
        $this->limits[$serverUuid] = $limit;
    }

    // --- contract ----------------------------------------------------------

    public function listBackups(string $serverUuid): array
    {
        return $this->backups[$serverUuid] ?? [];
    }

    public function createBackup(string $serverUuid, ?string $name = null, ?array $ignoredFiles = null): BackupRef
    {
        $this->tick();

        $limit = $this->limits[$serverUuid] ?? null;
        if ($limit !== null && $this->activeCount($serverUuid) >= $limit) {
            throw BackupException::limitReached();
        }

        // New backups start in progress (completed_at null, not yet successful).
        $ref = new BackupRef(
            uuid: 'bk-' . (++$this->seq),
            name: $name ?? ('backup-' . $this->seq),
            bytes: 0,
            isLocked: false,
            isSuccessful: false,
            createdAt: '2026-06-27T12:00:0' . ($this->seq % 10) . '+00:00',
            completedAt: null,
            id: $this->seq,
        );
        $this->backups[$serverUuid][] = $ref;
        $this->log[] = ['create', $serverUuid, $ref->uuid];

        return $ref;
    }

    public function deleteBackup(string $serverUuid, string $backupUuid): void
    {
        $this->tick();

        $list = $this->backups[$serverUuid] ?? [];
        foreach ($list as $b) {
            if ($b->uuid === $backupUuid) {
                if ($b->isLocked) {
                    throw BackupException::locked();
                }
                $this->backups[$serverUuid] = array_values(
                    array_filter($list, fn (BackupRef $x) => $x->uuid !== $backupUuid)
                );
                $this->log[] = ['delete', $serverUuid, $backupUuid];

                return;
            }
        }

        throw BackupException::notFound($serverUuid, $backupUuid);
    }

    /** Seconds the panel's backup rate limit would make a create wait (#129). */
    public int $throttleWait = 0;

    public function backupThrottleWait(string $serverUuid): int
    {
        return $this->throttleWait;
    }

    public function serverBackupLimit(string $serverUuid): ?int
    {
        return $this->limits[$serverUuid] ?? null;
    }

    /** @var array<string, array{loader: ?string, version: ?string}> */
    public array $versions = [];

    public function detectVersion(string $serverUuid): array
    {
        return $this->versions[$serverUuid] ?? ['loader' => null, 'version' => null];
    }

    /** @var array<string, array{power: ?string, install: ?string}> */
    public array $states = [];

    public function setServerVariables(string $serverUuid, array $env): void
    {
        $this->log[] = ['set_vars', $serverUuid, $env];
    }

    public function changeEgg(string $serverUuid, int $eggId, array $environment): void
    {
        $this->log[] = ['change_egg', $serverUuid, $eggId, $environment];
    }

    public function setDockerImage(string $serverUuid, string $image): void
    {
        $this->log[] = ['set_image', $serverUuid, $image];
    }

    public function reinstall(string $serverUuid): void
    {
        $this->log[] = ['reinstall', $serverUuid];
    }

    public function power(string $serverUuid, string $signal): void
    {
        $this->log[] = ['power', $serverUuid, $signal];
    }

    public function runtimeState(string $serverUuid): array
    {
        return $this->states[$serverUuid] ?? ['power' => 'offline', 'install' => null];
    }

    public function setBackupLocked(string $serverUuid, string $backupUuid, bool $locked): void
    {
        $this->tick();
        $this->mutateBackup($serverUuid, $backupUuid, isLocked: $locked);
        $this->log[] = ['lock', $serverUuid, $backupUuid, $locked];
    }

    public function renameBackup(string $serverUuid, string $backupUuid, string $name): void
    {
        $this->tick();
        $this->mutateBackup($serverUuid, $backupUuid, name: $name);
        $this->log[] = ['rename', $serverUuid, $backupUuid, $name];
    }

    public function downloadUrl(string $serverUuid, string $backupUuid, object $user): string
    {
        foreach ($this->backups[$serverUuid] ?? [] as $b) {
            if ($b->uuid === $backupUuid) {
                $this->log[] = ['download', $serverUuid, $backupUuid];

                return "https://example.test/download/{$backupUuid}";
            }
        }

        throw BackupException::notFound($serverUuid, $backupUuid);
    }

    public function restoreBackup(string $serverUuid, string $backupUuid, bool $truncate, object $user): void
    {
        foreach ($this->backups[$serverUuid] ?? [] as $b) {
            if ($b->uuid === $backupUuid) {
                if (! $b->isSuccessful || $b->completedAt === null) {
                    throw BackupException::panelError('Backup not restorable.');
                }
                $this->log[] = ['restore', $serverUuid, $backupUuid, $truncate];

                return;
            }
        }

        throw BackupException::notFound($serverUuid, $backupUuid);
    }

    /**
     * Replace a backup entry (BackupRef is readonly) with one field changed.
     */
    private function mutateBackup(string $serverUuid, string $backupUuid, ?bool $isLocked = null, ?string $name = null): void
    {
        $list = $this->backups[$serverUuid] ?? [];
        foreach ($list as $i => $b) {
            if ($b->uuid === $backupUuid) {
                $list[$i] = new BackupRef(
                    uuid: $b->uuid,
                    name: $name ?? $b->name,
                    bytes: $b->bytes,
                    isLocked: $isLocked ?? $b->isLocked,
                    isSuccessful: $b->isSuccessful,
                    createdAt: $b->createdAt,
                    completedAt: $b->completedAt,
                    id: $b->id,
                );
                $this->backups[$serverUuid] = $list;

                return;
            }
        }

        throw BackupException::notFound($serverUuid, $backupUuid);
    }

    // --- helpers -----------------------------------------------------------

    /** Non-failed backups: successful OR still in progress. */
    private function activeCount(string $serverUuid): int
    {
        return count(array_filter(
            $this->listBackups($serverUuid),
            fn (BackupRef $b) => $b->isSuccessful || $b->completedAt === null,
        ));
    }

    private function tick(): void
    {
        $this->opCount++;
        if ($this->throttleAfter !== null && $this->opCount > $this->throttleAfter) {
            throw BackupException::throttled();
        }
    }

    /** Count of completed, successful backups currently held. */
    public function successfulCount(string $serverUuid): int
    {
        return count(array_filter(
            $this->listBackups($serverUuid),
            fn (BackupRef $b) => $b->isSuccessful && $b->completedAt !== null,
        ));
    }
}
