<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\Notifier;
use ServerSuite\Services\PreChangeBackup;
use ServerSuite\Support\SuiteActivity;

/**
 * Install a server's pending mod updates for the scheduled check's --apply
 * (#120). It backs up first unless the server says never ("ask" can't be
 * answered unattended, so it backs up). While the backup runs, the job queues
 * itself again with a delay instead of sleeping, so no worker or scheduler
 * run is held up.
 */
class ApplyModUpdatesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $serverUuid,
        public ?string $backupUuid = null,
        public ?string $backupName = null,
        public ?int $deadline = null,
    ) {}

    /** Held while a server's apply is queued or waiting, so a second check run doesn't queue another. */
    public static function lockKey(string $serverUuid): string
    {
        return 'serversuite:apply-updates:' . $serverUuid;
    }

    /** Queue an apply for this server unless one is already on its way. */
    public static function queueFor(string $serverUuid): bool
    {
        $wait = (int) config('serversuite.backups.pre_change_wait_seconds', 1800);
        if (! Cache::add(self::lockKey($serverUuid), 1, $wait + 900)) {
            return false;
        }
        static::dispatch($serverUuid);

        return true;
    }

    public function handle(ModManagerService $mods, PreChangeBackup $pre, Notifier $notifier): void
    {
        $server = $mods->serverFor($this->serverUuid);
        // Suspended, or node in maintenance (#116): leave it alone.
        $blocked = \ServerSuite\Support\ServerState::blocker($server);
        if ($blocked !== null) {
            $this->finish($notifier, null, "Mod updates were not installed: {$blocked}");

            return;
        }

        if ($this->backupUuid === null) {
            $this->startBackup($pre, $notifier, $mods, $server);

            return;
        }

        $state = $pre->state($this->serverUuid, $this->backupUuid);
        if ($state === 'running') {
            if (time() >= (int) $this->deadline) {
                $this->finish($notifier, null, "Mod updates were not installed: the pre-update backup {$this->backupName} did not finish in time.");

                return;
            }
            $this->again($this->backupUuid, $this->backupName, (int) $this->deadline);

            return;
        }
        if ($state !== 'done') {
            $this->finish($notifier, null, "Mod updates were not installed: the pre-update backup {$this->backupName} failed.");

            return;
        }

        $this->apply($mods, $notifier, $server, $this->backupName);
    }

    private function startBackup(PreChangeBackup $pre, Notifier $notifier, ModManagerService $mods, object $server): void
    {
        if ((ModManagerSetting::forServer($this->serverUuid)->pre_change_backup ?? 'ask') === 'never') {
            $this->apply($mods, $notifier, $server, null);

            return;
        }

        $r = $pre->start($this->serverUuid, 'automatic mod updates');
        match ($r['status']) {
            'disabled' => $this->apply($mods, $notifier, $server, null),
            'recent'   => $this->apply($mods, $notifier, $server, $r['name'] ?? null),
            'started'  => $this->again($r['uuid'], $r['name'] ?? null,
                time() + (int) config('serversuite.backups.pre_change_wait_seconds', 1800)),
            default    => $this->finish($notifier, null, 'Mod updates were not installed: the pre-update backup could not be taken'
                . (isset($r['message']) ? " ({$r['message']})." : '.')),
        };
    }

    private function again(string $backupUuid, ?string $backupName, int $deadline): void
    {
        static::dispatch($this->serverUuid, $backupUuid, $backupName, $deadline)
            ->delay(now()->addSeconds(max(1, (int) config('serversuite.backups.pre_change_poll_seconds', 10))));
    }

    private function apply(ModManagerService $mods, Notifier $notifier, object $server, ?string $backup): void
    {
        $done = $mods->updateAll($server);
        if ($done['updated'] !== []) {
            app(\ServerSuite\Services\CrashHelpService::class)->recordChange($this->serverUuid, 'automatic mod updates',
                $backup !== null ? ['uuid' => null, 'name' => $backup] : null);
            SuiteActivity::logFor($this->serverUuid, 'server:serversuite.mods-auto-updated', [
                'mods'   => array_map(fn ($u) => "{$u['mod_name']} {$u['installed_version']}", $done['updated']),
                'failed' => array_column($done['failed'], 'mod_name'),
                'backup' => $backup,
            ]);
        }
        $this->finish($notifier, $done, null);
    }

    /** @param array{updated: array, failed: array}|null $done */
    private function finish(Notifier $notifier, ?array $done, ?string $problem): void
    {
        Cache::forget(self::lockKey($this->serverUuid));

        if ($problem !== null) {
            $notifier->notify($this->serverUuid, 'updates', 'Mod updates not installed', $problem, 'warn');

            return;
        }
        if ($done === null || ($done['updated'] === [] && $done['failed'] === [])) {
            return;
        }
        $lines = array_merge(
            array_map(fn ($u) => "Updated {$u['mod_name']} to {$u['installed_version']}", $done['updated']),
            array_map(fn ($f) => "Failed: {$f['mod_name']}: {$f['error']}", $done['failed']),
        );
        $notifier->notify($this->serverUuid, 'updates',
            $done['failed'] === [] ? count($done['updated']) . ' mod update(s) installed' : 'Some mod updates failed',
            implode("\n", array_slice($lines, 0, 25)), $done['failed'] === [] ? 'info' : 'warn');
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget(self::lockKey($this->serverUuid));
    }
}
