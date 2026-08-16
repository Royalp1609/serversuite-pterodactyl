<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Services\ManifestService;

class CheckUpdatesCommand extends Command
{
    protected $signature = 'serversuite:check-updates
                            {--server= : Only check a specific server UUID}
                            {--apply   : Apply available updates immediately (overrides config notify_only)}';

    protected $description = 'Check all installed mods for available updates and flag them in the manifest.';

    public function handle(RepositoryManager $repositories, ManifestService $manifest): int
    {
        $serverFilter = $this->option('server');
        $applyUpdates = $this->option('apply') || ! config('serversuite.scheduler.notify_only', true);

        $this->info('[Server Suite] Starting update check' . ($serverFilter ? " for server {$serverFilter}" : ' for all servers') . '.');

        // Fetch distinct server UUIDs that have auto_update_checks enabled
        $query = ModManagerSetting::where('auto_update_checks', true);

        if ($serverFilter) {
            $query->where('server_uuid', $serverFilter);
        }

        $settings = $query->get()->keyBy('server_uuid');

        if ($settings->isEmpty()) {
            $this->info('[Server Suite] No servers with update checks enabled.');
            return self::SUCCESS;
        }

        $serverUuids = $settings->keys()->toArray();

        $mods = ServerMod::whereIn('server_uuid', $serverUuids)->with('pendingUpdate')->get();

        $checked = 0;
        $flagged = 0;

        foreach ($mods as $serverMod) {
            $serverSettings = $settings->get($serverMod->server_uuid);
            $enabledRepos   = $serverSettings?->enabledRepositories() ?? [];

            if (! in_array($serverMod->source, $enabledRepos, true)) {
                continue;
            }

            try {
                $driver = $repositories->driver($serverMod->source);

                if (! $driver->isAvailable()) {
                    continue;
                }

                $latest = $driver->latestVersion(
                    $serverMod->source_id,
                    $serverMod->loader,
                    $serverMod->game_version
                );

                $checked++;

                if ($latest && $latest->versionNumber !== $serverMod->installed_version) {
                    $manifest->flagPendingUpdate($serverMod, $latest);
                    $flagged++;

                    $this->line(sprintf(
                        '  → %s (%s): %s → %s',
                        $serverMod->mod_name,
                        $serverMod->server_uuid,
                        $serverMod->installed_version,
                        $latest->versionNumber
                    ));

                    // TODO Phase 7: if $applyUpdates, dispatch UpdateModJob
                }
            } catch (\Throwable $e) {
                $this->warn("  ! Failed to check {$serverMod->mod_name} ({$serverMod->source_id}): {$e->getMessage()}");
            }
        }

        $this->info("[Server Suite] Done. Checked: {$checked} mods. Updates found: {$flagged}.");

        return self::SUCCESS;
    }
}
