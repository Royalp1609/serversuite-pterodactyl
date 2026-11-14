<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Jobs\ApplyModUpdatesJob;
use ServerSuite\Services\ModManagerService;

class CheckUpdatesCommand extends Command
{
    protected $signature = 'serversuite:check-updates
                            {--server= : Only check a specific server UUID}
                            {--apply   : Also install the updates found, behind a backup, in the background (also on when the admin turns on auto-apply)}';

    protected $description = 'Check all installed mods for available updates and flag them in the manifest.';

    public function handle(ModManagerService $mods): int
    {
        $serverFilter = $this->option('server');
        $apply = $this->option('apply') || ! config('serversuite.scheduler.notify_only', true);

        $this->info('[Server Suite] Starting update check' . ($serverFilter ? " for server {$serverFilter}" : ' for all servers') . '.');

        $query = ModManagerSetting::where('auto_update_checks', true);
        if ($serverFilter) {
            $query->where('server_uuid', $serverFilter);
        }
        $serverUuids = $query->pluck('server_uuid')->all();

        if ($serverUuids === []) {
            $this->info('[Server Suite] No servers with update checks enabled.');

            return self::SUCCESS;
        }

        $checked = $flagged = $queued = 0;
        foreach ($serverUuids as $uuid) {
            // Same lookup as an update (#120): the server's loader and game version.
            try {
                $r = $mods->checkUpdates($mods->serverFor($uuid));
            } catch (\Throwable $e) {
                $this->warn("  ! {$uuid}: check failed: {$e->getMessage()}");
                continue;
            }
            $checked += $r['checked'];
            $flagged += $r['updates'];
            foreach ($r['flagged'] as $line) {
                $this->line("  \u{2192} {$line} ({$uuid})");
            }
            if ($r['errors'] > 0) {
                $this->warn("  ! {$uuid}: {$r['errors']} mod(s) could not be checked");
            }

            // --apply / admin auto-apply: install in the background, behind a backup.
            if ($apply && $mods->applicableUpdates($uuid)->isNotEmpty() && ApplyModUpdatesJob::queueFor($uuid)) {
                $queued++;
            }
        }

        $this->notifyUpdates($serverUuids);

        $this->info("[Server Suite] Done. Checked: {$checked} mods. Updates found: {$flagged}."
            . ($apply ? " Servers queued to install: {$queued}." : ''));

        return self::SUCCESS;
    }

    /** #26: each server's waiting updates; the same list is sent once a day at most. */
    private function notifyUpdates(array $serverUuids): void
    {
        $notifier = app(\ServerSuite\Services\Notifier::class);
        foreach ($serverUuids as $uuid) {
            $pending = ServerMod::forServer($uuid)->with('pendingUpdate')->get()
                ->filter(fn ($m) => $m->pendingUpdate !== null && ! $m->pendingUpdate->dismissed);
            if ($pending->isEmpty()) {
                continue;
            }
            $lines = $pending->map(fn ($m) => "{$m->mod_name}: {$m->installed_version} → {$m->pendingUpdate->available_version}")->values()->all();
            $notifier->notify($uuid, 'updates', count($lines) . ' mod update(s) available', implode("\n", array_slice($lines, 0, 25))
                . (count($lines) > 25 ? "\n…" : '') . "\n\nInstall them from the Mods tab.", 'info', implode('|', $lines));
        }
    }

}
