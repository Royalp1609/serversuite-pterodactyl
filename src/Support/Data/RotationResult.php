<?php

namespace ServerSuite\Support\Data;

/**
 * The outcome of a single BackupRotationService::rotate() cycle.
 *
 * Consumed by the scheduler (logging), the "rotate now" UI action, and tests.
 * Deliberately descriptive rather than boolean — a cycle can legitimately do
 * nothing (skipped) for several distinct, non-error reasons that the UI should
 * be able to explain to the operator.
 *
 * status:
 *   rotated  — a new backup was created (and possibly one old one evicted).
 *   skipped  — nothing was done, for a benign reason (see ->reason).
 *   failed   — an operation errored in a way the operator should see.
 */
readonly class RotationResult
{
    public function __construct(
        public string $status,
        public ?BackupRef $created = null,
        /** @var BackupRef[] */
        public array $evicted = [],
        public ?string $reason = null,
        public ?string $message = null,
        public ?int $effectiveKeep = null,
    ) {}

    public static function rotated(?BackupRef $created, array $evicted, int $effectiveKeep): self
    {
        return new self(
            status: 'rotated',
            created: $created,
            evicted: $evicted,
            effectiveKeep: $effectiveKeep,
        );
    }

    public static function skipped(string $reason, ?string $message = null, array $evicted = []): self
    {
        return new self(
            status: 'skipped',
            evicted: $evicted,
            reason: $reason,
            message: $message ?? self::defaultMessage($reason),
        );
    }

    public static function failed(string $reason, ?string $message = null, array $evicted = []): self
    {
        return new self(
            status: 'failed',
            evicted: $evicted,
            reason: $reason,
            message: $message ?? self::defaultMessage($reason),
        );
    }

    public function isRotated(): bool
    {
        return $this->status === 'rotated';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function evictedCount(): int
    {
        return count($this->evicted);
    }

    public function toArray(): array
    {
        return [
            'status'         => $this->status,
            'created'        => $this->created?->toArray(),
            'evicted'        => array_map(fn (BackupRef $b) => $b->toArray(), $this->evicted),
            'reason'         => $this->reason,
            'message'        => $this->message,
            'effective_keep' => $this->effectiveKeep,
        ];
    }

    private static function defaultMessage(string $reason): string
    {
        return match ($reason) {
            'backups_disabled'        => 'Backups are disabled for this server (backup limit is 0).',
            'keep_below_one'          => 'The retention target is below 1, so no rolling backup is taken.',
            'backup_in_progress'      => 'A backup is already in progress; skipping this cycle.',
            'would_evict_last_success'=> 'The backup limit is too low to rotate without deleting the only good backup; raise the limit or unlock space.',
            'no_evictable_at_limit'   => 'At the backup limit and every backup is locked; nothing can be evicted to make room.',
            'all_locked'              => 'Retention exceeded but every eligible backup is locked.',
            'throttled'               => 'The panel backup throttle was hit; will retry next cycle.',
            'limit_reached_no_room'   => 'The panel reported the backup limit with no evictable backup to free.',
            default                   => $reason,
        };
    }
}
