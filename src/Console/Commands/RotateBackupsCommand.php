<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Support\Data\RotationResult;
use ServerSuite\Support\BackupSchedule;

class RotateBackupsCommand extends Command
{
    protected $signature = 'serversuite:rotate-backups
                            {--server= : Only rotate this server UUID (bypasses opt-in AND the schedule due-check)}
                            {--keep=   : Override the retention target (keep-N) for this run}';

    protected $description = 'Run rolling-backup rotation for servers whose per-server schedule is due.';

    public function handle(BackupRotationService $rotation): int
    {
        $serverFilter = $this->option('server');
        $keepOption   = $this->option('keep');
        $keepOverride = ($keepOption === null || $keepOption === '') ? null : (int) $keepOption;

        // A specific --server is an explicit, deliberate target: it bypasses the
        // per-server opt-in AND the schedule due-check, and does not advance the
        // schedule clock (last_run_at). It's an operator override, not a tick.
        if ($serverFilter) {
            return $this->rotateOne($rotation, $serverFilter, $keepOverride);
        }

        return $this->rotateDue($rotation, $keepOverride);
    }

    /**
     * Operator-forced rotation of a single server.
     */
    private function rotateOne(BackupRotationService $rotation, string $uuid, ?int $keepOverride): int
    {
        try {
            $result = $rotation->rotate($uuid, $keepOverride);
        } catch (\Throwable $e) {
            $this->warn("  ! {$uuid}: unexpected error — {$e->getMessage()}");

            return self::SUCCESS;
        }

        $this->line($this->formatLine($uuid, $result));

        return self::SUCCESS;
    }

    /**
     * The scheduled tick: rotate only opted-in servers whose interval has
     * elapsed since their last scheduled run.
     */
    private function rotateDue(BackupRotationService $rotation, ?int $keepOverride): int
    {
        $defaultInterval = max(1, (int) config('serversuite.backups.default_interval_hours', 12));
        // In the backup timezone, so a fixed hour matches what the owner picked.
        $now = BackupSchedule::now();

        $settings = ModManagerSetting::query()
            ->where('backup_rotation_enabled', true)
            ->get();

        // Fixed-hour vs. drifting schedules: see BackupSchedule.
        $due = $settings->filter(fn (ModManagerSetting $s) => BackupSchedule::isDue(
            (int) ($s->backup_interval_hours ?: $defaultInterval),
            $s->backup_schedule_hour,
            $s->backup_last_run_at,
            $now,
        ))->values();

        if ($due->isEmpty()) {
            $this->info('[Server Suite] No servers are due for a scheduled backup.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '[Server Suite] %d server%s due for a scheduled backup.',
            $due->count(),
            $due->count() === 1 ? '' : 's',
        ));

        $rotated = $skipped = $failed = 0;

        foreach ($due as $setting) {
            $uuid = $setting->server_uuid;

            try {
                $result = $rotation->rotate($uuid, $keepOverride);
            } catch (\Throwable $e) {
                $failed++;
                // Leave last_run_at untouched so a transient error retries next tick.
                $this->warn("  ! {$uuid}: unexpected error — {$e->getMessage()}");
                continue;
            }

            match (true) {
                $result->isRotated() => $rotated++,
                $result->isFailed()  => $failed++,
                default              => $skipped++,
            };

            // Advance the schedule clock on a deliberate outcome (rotated or
            // skipped). Failures are left to retry on the next tick.
            if (! $result->isFailed()) {
                $setting->forceFill(['backup_last_run_at' => $now])->save();
            }

            $this->line($this->formatLine($uuid, $result));
        }

        $this->info("[Server Suite] Done. Rotated: {$rotated}, skipped: {$skipped}, failed: {$failed}.");

        return self::SUCCESS;
    }

    private function formatLine(string $uuid, RotationResult $result): string
    {
        if ($result->isRotated()) {
            $created = $result->created?->name ?? 'backup';
            $evicted = $result->evictedCount() > 0
                ? ", evicted {$result->evictedCount()}"
                : '';

            return "  ✓ {$uuid}: created \"{$created}\"{$evicted}";
        }

        if ($result->isFailed()) {
            return "  ! {$uuid}: failed ({$result->reason}) — {$result->message}";
        }

        return "  - {$uuid}: skipped ({$result->reason})";
    }
}
