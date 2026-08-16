<?php

namespace ServerSuite\Support\Data;

/**
 * A panel-agnostic snapshot of a single server backup.
 *
 * This is the ONLY backup shape that crosses the PanelServerOps seam — the
 * panel's own Backup model (Pterodactyl\Models\Backup, or Pelican's
 * equivalent) never leaks past the implementation. The rotation policy engine
 * and the UI both consume this DTO and nothing else.
 *
 * Field provenance (verified live, see HANDOFF-backup-build.md probe table):
 *   - uuid / name / bytes / is_locked / is_successful / completed_at all
 *     present and sane on the Pterodactyl Backup model.
 *   - completedAt is null while a backup is still in progress, which the
 *     eviction query relies on to exclude running backups.
 */
readonly class BackupRef
{
    public function __construct(
        /** Public backup identifier (the uuid used in panel/daemon routes). */
        public string $uuid,

        /** Human-readable backup name. */
        public string $name,

        /** Size on disk in bytes (0 until the backup completes). */
        public int $bytes,

        /** True when the backup is pinned "keep-forever" and must never be evicted. */
        public bool $isLocked,

        /** True only once the backup finished successfully. */
        public bool $isSuccessful,

        /** ISO-8601 creation timestamp, or null if unavailable. */
        public ?string $createdAt = null,

        /**
         * ISO-8601 completion timestamp. Null while in progress. Eviction
         * ordering uses this (not created_at, not name) to pick the oldest.
         */
        public ?string $completedAt = null,

        /** Internal numeric id where the panel exposes one (0 if unknown). */
        public int $id = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'uuid'          => $this->uuid,
            'name'          => $this->name,
            'bytes'         => $this->bytes,
            'is_locked'     => $this->isLocked,
            'is_successful' => $this->isSuccessful,
            'created_at'    => $this->createdAt,
            'completed_at'  => $this->completedAt,
            'id'            => $this->id,
        ];
    }
}
