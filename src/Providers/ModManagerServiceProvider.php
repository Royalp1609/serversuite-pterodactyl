<?php

namespace ServerSuite\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\ManifestService;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\PanelCompatService;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Support\PanelPermissions;
use ServerSuite\Models\ModManagerGlobalSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use ServerSuite\Console\Commands\CheckUpdatesCommand;
use ServerSuite\Console\Commands\RotateBackupsCommand;
use ServerSuite\Console\Commands\MoveSubdomainsCommand;

class ModManagerServiceProvider extends ServiceProvider
{
    /** Referenced by name so the package still loads where the panel isn't present (tests). */
    private const SERVER_MODEL = '\\Pterodactyl\\Models\\Server';

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/serversuite.php',
            'serversuite'
        );

        // Bind the repository manager (driver registry)
        $this->app->singleton(RepositoryManager::class, function ($app) {
            return new RepositoryManager($app);
        });

        // Core services
        $this->app->singleton(LoaderDetectionService::class);
        $this->app->singleton(FileDeliveryService::class);
        $this->app->singleton(ManifestService::class);
        $this->app->singleton(\ServerSuite\Services\InstallPlanner::class);
        $this->app->singleton(ModManagerService::class);

        // Daemon file client — bound to the panel-appropriate implementation.
        // This is the single point of panel coupling for file operations.
        $this->app->singleton(\ServerSuite\Contracts\DaemonFileClient::class, function ($app) {
            /** @var PanelCompatService $compat */
            $compat = $app->make(PanelCompatService::class);

            if ($compat->isPelican()) {
                // Pelican implementation arrives in Phase 8.
                if (class_exists(\ServerSuite\Daemon\PelicanDaemonFileClient::class)) {
                    return $app->make(\ServerSuite\Daemon\PelicanDaemonFileClient::class);
                }
            }

            return $app->make(\ServerSuite\Daemon\PterodactylDaemonFileClient::class);
        });

        // Panel server-operations client (backups) — the sister seam to
        // DaemonFileClient, also bound to the panel-appropriate implementation.
        // This is the single point of panel coupling for backup operations.
        $this->app->singleton(\ServerSuite\Contracts\PanelServerOps::class, function ($app) {
            /** @var PanelCompatService $compat */
            $compat = $app->make(PanelCompatService::class);

            if ($compat->isPelican()) {
                // Pelican implementation arrives alongside its UI provider.
                if (class_exists(\ServerSuite\Panel\PelicanPanelServerOps::class)) {
                    return $app->make(\ServerSuite\Panel\PelicanPanelServerOps::class);
                }
            }

            return $app->make(\ServerSuite\Panel\PterodactylPanelServerOps::class);
        });

        // Backup rotation — retention resolver + policy engine. The engine
        // depends only on the PanelServerOps contract above, so it is fully
        // panel-agnostic and unit-testable.
        $this->app->singleton(\ServerSuite\Services\SubdomainService::class);
        $this->app->singleton(\ServerSuite\Services\StatusCardService::class);

        $this->app->singleton(\ServerSuite\Services\BackupRetentionResolver::class);
        $this->app->singleton(\ServerSuite\Services\BackupRotationService::class, function ($app) {
            return new \ServerSuite\Services\BackupRotationService(
                $app->make(\ServerSuite\Contracts\PanelServerOps::class),
                $app->make(\ServerSuite\Services\BackupRetentionResolver::class),
            );
        });

        // Panel compatibility shim — resolves which UI layer to load
        $this->app->singleton(PanelCompatService::class, function ($app) {
            return new PanelCompatService(config('serversuite.panel', 'auto'));
        });
    }

    public function boot(): void
    {
        $this->registerPublishables();
        $this->registerMigrations();
        $this->registerStatusCards();
        $this->registerPermissions();
        $this->registerCommands();
        $this->applyGlobalSettingOverrides();
        $this->registerScheduler();
        $this->registerServerHooks();
        $this->loadPanelUi();
    }

    /**
     * Keep subdomain DNS in step with the panel (#14): remove the records when
     * a server is deleted, and when its primary allocation changes, mark them
     * to move once the server has started on it (#37,
     * serversuite:move-subdomains). Moving straight away broke the address
     * until the next restart, since the game still listens on the old port.
     * Best effort: a DNS failure must never block the panel action.
     * Pterodactyl deletes servers and changes allocations inside a
     * transaction, so the DNS calls wait for the commit (#35): a rolled-back
     * change keeps its DNS.
     */
    protected function registerServerHooks(): void
    {
        if (! class_exists(self::SERVER_MODEL)) {
            return;
        }
        $server = self::SERVER_MODEL;

        $server::deleted(function ($s) {
            $uuid = (string) $s->uuid;
            DB::afterCommit(function () use ($uuid) {
                try {
                    app(\ServerSuite\Services\SubdomainService::class)->cleanupServer($uuid);
                } catch (\Throwable $e) {
                    logger()->warning('[Server Suite] subdomain cleanup failed', ['server' => $uuid, 'error' => $e->getMessage()]);
                }
            });
        });

        // An install or reinstall finished, however it was started: make
        // sure the startup finds a jar (Forge 26.x leaves no server.jar).
        // A reinstall or restore starts (#69): remember whether the server was
        // running, to start it again afterwards. Read here, in the panel's own
        // request and before it tells Wings (which stops the server), not
        // after commit. A failed install forgets it.
        $server::updated(function ($s) {
            if (! $s->wasChanged('status')) {
                return;
            }
            if (in_array($s->status, ['installing', 'restoring_backup'], true)) {
                app(\ServerSuite\Services\AutoStartService::class)->remember((string) $s->uuid);
            } elseif (in_array($s->status, ['install_failed', 'reinstall_failed'], true)) {
                app(\ServerSuite\Services\AutoStartService::class)->forget((string) $s->uuid);
            }
        });

        $server::updated(function ($s) {
            if (! $s->wasChanged('status') || $s->getOriginal('status') !== 'installing' || $s->status !== null) {
                return;
            }
            $uuid = (string) $s->uuid;
            DB::afterCommit(function () use ($uuid) {
                \ServerSuite\Jobs\SyncAfterInstallJob::dispatch($uuid);
            });
        });

        // A backup restore finished (#60): status goes from restoring_backup
        // to null, however the restore was started.
        $server::updated(function ($s) {
            if (! $s->wasChanged('status') || $s->getOriginal('status') !== 'restoring_backup' || $s->status !== null) {
                return;
            }
            $uuid = (string) $s->uuid;
            DB::afterCommit(function () use ($uuid) {
                \ServerSuite\Jobs\SyncAfterRestoreJob::dispatch($uuid)->delay(now()->addSeconds(10));
            });
        });

        $server::updated(function ($s) {
            if (! $s->wasChanged('allocation_id')) {
                return;
            }
            $uuid = (string) $s->uuid;
            DB::afterCommit(function () use ($uuid) {
                try {
                    app(\ServerSuite\Services\SubdomainService::class)->markMovePending($uuid);
                } catch (\Throwable $e) {
                    logger()->warning('[Server Suite] subdomain move mark failed', ['server' => $uuid, 'error' => $e->getMessage()]);
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // Publishables
    // -------------------------------------------------------------------------

    protected function registerPublishables(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../../config/serversuite.php' => config_path('serversuite.php'),
        ], 'serversuite-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'serversuite-migrations');
    }

    // -------------------------------------------------------------------------
    // Migrations
    // -------------------------------------------------------------------------

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }

    /**
     * Public status cards (#29): /status/<fqdn> on the panel's own address.
     * Loaded before the panel's routes, so its React catch-all doesn't take
     * the path.
     */
    protected function registerStatusCards(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'serversuite');
        $this->loadRoutesFrom(__DIR__ . '/../../routes/status.php');
        // Billing API (#38): needs the panel's api / application-api middleware.
        if (class_exists('\\Pterodactyl\\Http\\Kernel')) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        }
    }

    // -------------------------------------------------------------------------
    // Permissions (#17)
    // -------------------------------------------------------------------------

    /**
     * Pterodactyl's own subuser permissions decide everything (see
     * PanelPermissions). This adds the mods and subdomains groups to the
     * panel's list so they can be granted on its subuser screen.
     */
    protected function registerPermissions(): void
    {
        PanelPermissions::register();
        $this->registerActivityLabels();
    }

    /**
     * Readable Server Suite entries in the Activity tab (#19): the panel's
     * locale endpoint reads the "activity" group through the translation
     * loader, which merges every path it has.
     */
    protected function registerActivityLabels(): void
    {
        $this->callAfterResolving('translation.loader', function ($loader) {
            if (method_exists($loader, 'addPath')) {
                $loader->addPath(__DIR__ . '/../../lang');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Artisan Commands
    // -------------------------------------------------------------------------

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckUpdatesCommand::class,
                RotateBackupsCommand::class,
                MoveSubdomainsCommand::class,
                \ServerSuite\Console\Commands\WatchCrashesCommand::class,
                \ServerSuite\Console\Commands\AutoUpgradesCommand::class,
                \ServerSuite\Console\Commands\CheckDnsTokensCommand::class,
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Scheduler
    // -------------------------------------------------------------------------

    /**
     * Map admin-saved global settings onto the config repository, so config
     * stays the single read point for every driver and service. Values stored
     * by the admin settings page WIN over config/.env; unset keys fall back.
     *
     * Guarded for environments where the table doesn't exist yet (pre-migrate,
     * console during install) — overrides are simply skipped.
     */
    protected function applyGlobalSettingOverrides(): void
    {
        try {
            if (! Schema::hasTable('mod_manager_global_settings')) {
                return;
            }

            $map = [
                'repo_modrinth_enabled'    => ['serversuite.repositories.modrinth',        'bool'],
                'repo_curseforge_enabled'  => ['serversuite.repositories.curseforge',      'bool'],
                'repo_hangar_enabled'      => ['serversuite.repositories.hangar',          'bool'],
                'curseforge_api_key'       => ['serversuite.curseforge_api_key',           'string'],
                'scheduler_interval'       => ['serversuite.scheduler.interval',           'string'],
                'scheduler_notify_only'    => ['serversuite.scheduler.notify_only',        'bool'],
                'auto_update_on_conflict'  => ['serversuite.install.auto_update_on_conflict', 'bool'],
                'backup_rotation_enabled'  => ['serversuite.backups.enabled',                'bool'],
                'backup_keep_default'      => ['serversuite.backups.keep_default',           'string'],
                'backup_schedule'          => ['serversuite.backups.schedule',               'string'],
                'backup_default_interval_hours' => ['serversuite.backups.default_interval_hours', 'string'],
                'backup_timezone'          => ['serversuite.backups.timezone',               'string'],
                'auto_upgrades_enabled'    => ['serversuite.auto_upgrades.enabled',          'bool'],
                'auto_upgrades_min_age_days' => ['serversuite.auto_upgrades.min_age_days',   'string'],
            ];

            foreach (ModManagerGlobalSetting::allSettings() as $key => $value) {
                if (! isset($map[$key]) || $value === null || $value === '') {
                    continue;
                }
                [$configKey, $type] = $map[$key];
                config([$configKey => $type === 'bool' ? (bool) (int) $value : $value]);
            }
        } catch (\Throwable $e) {
            // Settings overrides are never worth breaking boot for.
        }
    }

    protected function registerScheduler(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $args = config('serversuite.scheduler.notify_only', true) ? [] : ['--apply'];

            $schedule->command(CheckUpdatesCommand::class, $args)
                ->cron(config('serversuite.scheduler.interval', '0 3 * * *'))
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/serversuite-updates.log'));

            // Subdomains waiting for their server to start on a new
            // allocation (#37). Cheap when nothing is waiting.
            $schedule->command(MoveSubdomainsCommand::class)
                ->everyMinute()
                ->withoutOverlapping();

            // Admin domains' Cloudflare tokens: warn before they expire and
            // after they stop working (#139). Two requests per domain.
            $schedule->command(\ServerSuite\Console\Commands\CheckDnsTokensCommand::class)
                ->dailyAt('06:10')
                ->withoutOverlapping();

            // Crash after a mod change, for servers with notifications (#26).
            $schedule->command(\ServerSuite\Console\Commands\WatchCrashesCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping();

            // Opt-in version upgrades (#111). In the background because plugin
            // updates can take a while; the lock outlives a crash by 30 minutes.
            $schedule->command(\ServerSuite\Console\Commands\AutoUpgradesCommand::class)
                ->everyMinute()
                ->withoutOverlapping(30)
                ->runInBackground();

            // Rolling-backup rotation — only scheduled when the master switch is
            // on. Runs on a fixed, frequent TICK; the command itself decides
            // which servers are due based on each server's interval + last run.
            // The tick MUST be at least as frequent as the smallest preset.
            if (config('serversuite.backups.enabled', false)) {
                $schedule->command(RotateBackupsCommand::class)
                    ->cron(config('serversuite.backups.tick_cron', '*/15 * * * *'))
                    ->withoutOverlapping()
                    ->runInBackground()
                    ->appendOutputTo(storage_path('logs/serversuite-backups.log'));
            }
        });
    }

    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Panel UI — load the correct UI service provider for the detected panel
    // -------------------------------------------------------------------------

    protected function loadPanelUi(): void
    {
        /** @var PanelCompatService $compat */
        $compat = $this->app->make(PanelCompatService::class);

        if ($compat->isPelican()) {
            // Filament UI provider for Pelican — registered in Phase 8
            if (class_exists(\ServerSuite\Providers\PelicanUiServiceProvider::class)) {
                $this->app->register(\ServerSuite\Providers\PelicanUiServiceProvider::class);
            }
        } else {
            // Blade/Vue UI provider for classic Pterodactyl — registered in Phase 5
            if (class_exists(\ServerSuite\Providers\PterodactylUiServiceProvider::class)) {
                $this->app->register(\ServerSuite\Providers\PterodactylUiServiceProvider::class);
            }
        }
    }
}
