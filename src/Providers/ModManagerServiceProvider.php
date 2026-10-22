<?php

namespace ServerSuite\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Console\Scheduling\Schedule;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\ManifestService;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\PanelCompatService;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Policies\ServerModPolicy;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerGlobalSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use ServerSuite\Console\Commands\CheckUpdatesCommand;
use ServerSuite\Console\Commands\RotateBackupsCommand;
use ServerSuite\Console\Commands\MoveSubdomainsCommand;

class ModManagerServiceProvider extends ServiceProvider
{
    /**
     * All model policy bindings.
     *
     * @var array<string, string>
     */
    /** Referenced by name so the package still loads where the panel isn't present (tests). */
    private const SERVER_MODEL = '\\Pterodactyl\\Models\\Server';

    protected array $policies = [
        ServerMod::class => ServerModPolicy::class,
    ];

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
        $this->registerPolicies();
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

    // -------------------------------------------------------------------------
    // Policies
    // -------------------------------------------------------------------------

    protected function registerPolicies(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Named permission gates delegate to the policy so authorization logic
        // lives in exactly one place (ServerModPolicy). Each gate maps to the
        // matching policy ability and is called as Gate::allows('mod_manager.install', $server).
        $abilityMap = [
            'view'    => 'view',
            'install' => 'install',
            'update'  => 'update',
            'remove'  => 'remove',
            'manage'  => 'manage',
        ];

        foreach (config('serversuite.permissions', []) as $ability => $node) {
            if (! isset($abilityMap[$ability])) {
                continue;
            }

            $policyAbility = $abilityMap[$ability];

            Gate::define($node, function ($user, $server) use ($policyAbility) {
                /** @var ServerModPolicy $policy */
                $policy = app(ServerModPolicy::class);

                // Honour the policy's before() hook (root admin bypass)
                $before = $policy->before($user, $policyAbility);
                if ($before !== null) {
                    return $before;
                }

                return $policy->{$policyAbility}($user, $server);
            });
        }
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
