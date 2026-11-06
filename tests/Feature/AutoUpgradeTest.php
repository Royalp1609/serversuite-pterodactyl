<?php

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\NetworkMember;
use ServerSuite\Models\ServerMod;
use ServerSuite\Services\AutoStartService;
use ServerSuite\Services\AutoUpgradeService;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\Notifier;
use ServerSuite\Services\PostSwitchService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Services\StatusCardService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\AutoUpgradePolicy;
use ServerSuite\Support\BackupSchedule;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\Data\RotationResult;
use ServerSuite\Support\MinecraftPing;
use ServerSuite\Support\RepositoryManager;

/** #111: each thing that must hold an unattended upgrade, and the full run. */
class AutoUpgradeTest extends \ServerSuite\Tests\TestCase
{
    private \Pterodactyl\Models\Server $server;
    private ?int $limit = 5;
    private array $state = ['power' => 'running', 'install' => null];
    /** @var array<string, mixed>|null */
    private ?array $status = ['players' => ['online' => 0], 'version' => ['name' => 'Paper 1.21.5']];
    private array $versionList = ['1.21.5', '1.21.4', '1.21', '1.20.6'];
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $builds = [];
    private array $backups = [];
    private ?RotationResult $backupResult = null;
    private array $jars = [];
    private ?\Throwable $jarsError = null;
    /** @var array<int, array<string, mixed>> */
    private array $applied = [];
    private array $signals = [];
    private array $notified = [];
    private array $order = [];
    private ?string $dlPath = null;
    private array $preflightBlockers = [];
    private bool $updateError = false;
    /** @var array<string, bool> plugin source id => has a build for the target */
    private array $pluginBuilds = [];

    protected function setUp(): void
    {
        parent::setUp();
        panelTables();
        Cache::flush();

        $this->server = $this->makeServer();
        $this->builds['1.21.5'] = [
            ['build' => 12, 'channel' => 'STABLE', 'download_url' => 'https://fill.test/12.jar', 'time' => now()->subDays(20)->toIso8601String()],
            ['build' => 3, 'channel' => 'STABLE', 'download_url' => 'https://fill.test/3.jar', 'time' => now()->subDays(30)->toIso8601String()],
        ];

        $ops = \Mockery::mock(PanelServerOps::class)->shouldIgnoreMissing();
        $ops->shouldReceive('serverBackupLimit')->andReturnUsing(fn () => $this->limit);
        $ops->shouldReceive('runtimeState')->andReturnUsing(fn () => $this->state);
        $ops->shouldReceive('listBackups')->andReturnUsing(fn () => $this->backups);
        $ops->shouldReceive('power')->andReturnUsing(function ($uuid, $signal) {
            $this->signals[] = $signal;
            $this->order[] = 'power:' . $signal;
        });
        $this->app->instance(PanelServerOps::class, $ops);

        $daemon = \Mockery::mock(DaemonFileClient::class)->shouldIgnoreMissing();
        $daemon->shouldReceive('listDirectory')->andReturnUsing(function () {
            if ($this->jarsError) {
                throw $this->jarsError;
            }

            return array_map(fn ($j) => ['name' => $j, 'is_file' => true], $this->jars);
        });
        $this->app->instance(DaemonFileClient::class, $daemon);

        $versions = \Mockery::mock(ServerVersionService::class);
        $versions->shouldReceive('available')->andReturnUsing(fn () => ['supported' => true, 'versions' => $this->versionList]);
        $versions->shouldReceive('paperBuilds')->andReturnUsing(fn ($v) => $this->builds[$v] ?? []);
        $versions->shouldReceive('current')->andReturn(['loader' => 'paper', 'game_version' => '1.21.4']);
        $versions->shouldReceive('preflight')->andReturnUsing(fn ($server, $v, $build) => [
            'can_proceed' => $this->preflightBlockers === [], 'blockers' => $this->preflightBlockers, 'direction' => 'upgrade',
            'target' => ['version' => $v, 'build' => $build, 'download_url' => $this->builds[$v][0]['download_url'] ?? null],
        ]);
        $this->app->instance(ServerVersionService::class, $versions);

        $switch = \Mockery::mock(VersionSwitchService::class);
        $switch->shouldReceive('apply')->andReturnUsing(function ($server, $version, $build, $requireBackup, $deletePlugins, $wipe, $download = null) {
            $this->applied[] = compact('version', 'build', 'requireBackup', 'deletePlugins', 'wipe', 'download');
            $this->order[] = 'apply';
            ModManagerSetting::forServer($server->uuid)->update(['game_version' => $version]);

            return ['status' => 'reinstalling'];
        });
        $this->app->instance(VersionSwitchService::class, $switch);

        $rotation = \Mockery::mock(BackupRotationService::class);
        $rotation->shouldReceive('backupNow')->andReturnUsing(function ($uuid, $name) {
            if ($this->backupResult) {
                return $this->backupResult;
            }
            $ref = new BackupRef('b-1', "v1.21.4 Paper · {$name}", 0, false, false);
            $this->backups = [$ref];

            return RotationResult::rotated($ref, [], 5);
        });
        $this->app->instance(BackupRotationService::class, $rotation);

        $mods = \Mockery::mock(ModManagerService::class);
        $mods->shouldReceive('update')->andReturnUsing(function ($mod) {
            $this->order[] = 'plugins';
            if ($this->updateError) {
                throw new \RuntimeException('delivery failed');
            }

            return $mod;
        });
        $this->app->instance(ModManagerService::class, $mods);

        $driver = \Mockery::mock(RepositoryContract::class);
        $driver->shouldReceive('latestVersion')->andReturnUsing(function ($id) {
            return ($this->pluginBuilds[$id] ?? true)
                ? new ModVersion(versionId: 'v2', versionNumber: '2', loaders: ['paper'], gameVersions: ['1.21.5'], downloadUrl: 'https://x.test/a.jar', filename: 'a.jar', fileSize: 1, changelog: null)
                : null;
        });
        $repositories = \Mockery::mock(RepositoryManager::class);
        $repositories->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(RepositoryManager::class, $repositories);

        $cards = \Mockery::mock(StatusCardService::class);
        $cards->shouldReceive('target')->andReturn(['10.0.0.1', 25565]);
        $this->app->instance(StatusCardService::class, $cards);

        $ping = \Mockery::mock(MinecraftPing::class);
        $ping->shouldReceive('ping')->andReturnUsing(function () {
            return $this->status ?? throw new \RuntimeException('down');
        });
        $this->app->instance(MinecraftPing::class, $ping);

        $post = \Mockery::mock(PostSwitchService::class);
        $post->shouldReceive('finish')->andReturn([]);
        $this->app->instance(PostSwitchService::class, $post);

        $this->app->instance(AutoStartService::class, \Mockery::mock(AutoStartService::class)->shouldIgnoreMissing());

        $loaders = \Mockery::mock(LoaderDetectionService::class);
        $loaders->shouldReceive('serverVariable')->andReturnUsing(fn () => $this->dlPath);
        $this->app->instance(LoaderDetectionService::class, $loaders);

        $notifier = \Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->andReturnUsing(function ($uuid, $event, $title, $body = '', $level = 'info') {
            $this->notified[] = compact('event', 'title', 'body', 'level');
        });
        $this->app->instance(Notifier::class, $notifier);

        $this->optIn();
    }

    private function makeServer(): \Pterodactyl\Models\Server
    {
        return \Pterodactyl\Models\Server::query()->forceCreate(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'backup_limit' => 5]);
    }

    private function optIn(array $over = []): void
    {
        ModManagerSetting::forServer($this->server->uuid)->forceFill($over + [
            'loader' => 'paper', 'game_version' => '1.21.4', 'auto_upgrade_enabled' => true,
            'auto_upgrade_range' => 'line', 'auto_upgrade_hour' => BackupSchedule::now()->hour,
        ])->save();
    }

    private function tick(): void
    {
        ModManagerSetting::forServer($this->server->uuid)->forceFill(['auto_upgrade_checked_at' => null])->save();
        app(AutoUpgradeService::class)->tick();
    }

    private function setting(): ModManagerSetting
    {
        return ModManagerSetting::forServer($this->server->uuid);
    }

    private function code(): ?string
    {
        return $this->setting()->auto_upgrade_status['code'] ?? null;
    }

    private function assertHeld(string $code): void
    {
        $this->tick();
        $this->assertSame($code, $this->code());
        $this->assertNull($this->setting()->auto_upgrade_run, 'no upgrade started');
    }

    private function finishBackup(bool $ok = true): void
    {
        $name = $this->backups[0]->name;
        $this->backups = [new BackupRef('b-1', $name, 10, false, $ok, now()->toIso8601String(), now()->toIso8601String())];
    }

    public function test_it_is_off_unless_a_server_opts_in(): void
    {
        $this->optIn(['auto_upgrade_enabled' => false]);
        $this->tick();
        $this->assertNull($this->setting()->auto_upgrade_run);
        $this->assertNull($this->code());
    }

    public function test_full_run_keeps_plugins_updates_them_before_boot_and_reports(): void
    {
        $this->tick();
        $run = $this->setting()->auto_upgrade_run;
        $this->assertSame('backing_up', $run['phase']);
        $this->assertSame('1.21.5', $run['to']);
        $this->assertSame([], $this->applied, 'nothing before the backup finishes');

        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->applied, 'still waiting on the backup');

        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([['version' => '1.21.5', 'build' => 12, 'requireBackup' => true, 'deletePlugins' => false, 'wipe' => 'none', 'download' => 'https://fill.test/12.jar']], $this->applied);
        $this->assertSame('reinstalling', $this->setting()->auto_upgrade_run['phase']);

        $this->state = ['power' => 'offline', 'install' => 'installing'];
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->signals, 'not started during the install');

        $this->state = ['power' => 'offline', 'install' => null];
        $this->status = null;
        ServerMod::query()->forceCreate(['server_uuid' => $this->server->uuid, 'source' => 'modrinth', 'source_id' => 'ess', 'mod_name' => 'EssentialsX', 'installed_version' => '1', 'target_filename' => 'Essentials.jar']);
        app(AutoUpgradeService::class)->tick();
        $this->assertSame('booting', $this->setting()->auto_upgrade_run['phase']);
        $this->assertSame(['apply', 'plugins', 'power:start'], $this->order, 'plugins before the first boot');

        $this->status = ['players' => ['online' => 0], 'version' => ['name' => 'Paper 1.21.5']];
        app(AutoUpgradeService::class)->tick();
        $this->assertNull($this->setting()->auto_upgrade_run);
        $this->assertSame('upgraded', $this->code());
        $this->assertSame('Upgraded to Minecraft 1.21.5', end($this->notified)['title']);
        $this->assertStringContainsString('EssentialsX', end($this->notified)['body']);
        $this->assertStringContainsString('Pre-upgrade', end($this->notified)['body']);
    }

    public function test_a_server_with_no_backup_allocation_never_upgrades(): void
    {
        $this->limit = 0;
        $this->assertHeld('no_backups');
        $this->assertSame('warn', $this->notified[0]['level']);
        $this->expectException(\RuntimeException::class);
        app(AutoUpgradeService::class)->configure($this->server, true, 'line', 4);
    }

    public function test_a_hold_is_only_notified_once(): void
    {
        $this->limit = 0;
        $this->tick();
        $this->tick();
        $this->tick();
        $this->assertCount(1, $this->notified);
    }

    public function test_only_paper(): void
    {
        $this->optIn(['loader' => 'fabric']);
        $this->assertHeld('loader');
    }

    public function test_network_members_are_skipped(): void
    {
        NetworkMember::query()->forceCreate(['network_id' => 1, 'server_uuid' => $this->server->uuid, 'name' => 'lobby', 'position' => 0]);
        $this->assertHeld('network');
    }

    public function test_only_within_the_chosen_hour(): void
    {
        $this->optIn(['auto_upgrade_hour' => (BackupSchedule::now()->hour + 1) % 24]);
        $this->tick();
        $this->assertNull($this->setting()->auto_upgrade_run);
        $this->assertNull($this->code());
    }

    public function test_the_admin_switch_stops_new_upgrades(): void
    {
        config(['serversuite.auto_upgrades.enabled' => false]);
        $this->tick();
        $this->assertNull($this->setting()->auto_upgrade_run);
    }

    public function test_turning_it_off_cancels_an_upgrade_still_on_its_backup(): void
    {
        $this->tick();
        $this->setting()->forceFill(['auto_upgrade_enabled' => false])->save();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->applied);
        $this->assertNull($this->setting()->auto_upgrade_run);
    }

    public function test_players_online_holds(): void
    {
        $this->status = ['players' => ['online' => 2]];
        $this->assertHeld('players_online');
        $this->assertSame([], $this->notified, 'normal, so no message');
    }

    public function test_unknown_player_count_holds(): void
    {
        $this->status = null;
        $this->assertHeld('players_unknown');
    }

    public function test_a_stopped_server_stays_stopped(): void
    {
        $this->state = ['power' => 'offline', 'install' => null];
        $this->assertHeld('not_running');
        $this->assertSame([], $this->signals);
    }

    public function test_a_release_that_is_too_new_waits_and_an_older_eligible_one_is_taken(): void
    {
        $this->builds['1.21.5'] = [['build' => 2, 'channel' => 'STABLE', 'download_url' => 'x', 'time' => now()->subDays(2)->toIso8601String()]];
        $this->assertHeld('waiting');

        $this->versionList = ['1.21.6', '1.21.5', '1.21.4'];
        $this->builds['1.21.6'] = $this->builds['1.21.5'];
        $this->builds['1.21.5'] = [['build' => 9, 'channel' => 'STABLE', 'download_url' => 'x', 'time' => now()->subDays(9)->toIso8601String()]];
        $this->tick();
        $this->assertSame('1.21.5', $this->setting()->auto_upgrade_run['to']);
    }

    public function test_a_version_with_only_experimental_builds_is_not_taken(): void
    {
        $this->builds['1.21.5'] = [['build' => 2, 'channel' => 'ALPHA', 'download_url' => 'x', 'time' => now()->subDays(40)->toIso8601String()]];
        $this->assertHeld('waiting');
    }

    public function test_the_build_is_the_newest_stable_not_a_newer_experimental_one(): void
    {
        array_unshift($this->builds['1.21.5'], ['build' => 20, 'channel' => 'BETA', 'download_url' => 'x', 'time' => now()->toIso8601String()]);
        $this->tick();
        $this->assertSame(12, $this->setting()->auto_upgrade_run['build']);
    }

    public function test_line_range_stops_at_the_end_of_the_line_and_says_so_once(): void
    {
        $this->versionList = ['26.1', '1.21.4'];
        $this->assertHeld('line_ended');
        $this->tick();
        $this->assertCount(1, $this->notified);
        $this->assertStringContainsString('26.1', $this->notified[0]['body']);
    }

    public function test_latest_range_crosses_lines(): void
    {
        $this->optIn(['auto_upgrade_range' => 'latest']);
        $this->versionList = ['26.1', '1.21.4'];
        $this->builds['26.1'] = $this->builds['1.21.5'];
        $this->tick();
        $this->assertSame('26.1', $this->setting()->auto_upgrade_run['to']);
    }

    public function test_up_to_date_is_quiet(): void
    {
        $this->versionList = ['1.21.4'];
        $this->assertHeld('up_to_date');
        $this->assertSame([], $this->notified);
    }

    public function test_a_plugin_we_cannot_identify_holds(): void
    {
        $this->jars = ['Mystery.jar'];
        $this->assertHeld('plugins');
        $this->assertStringContainsString('Mystery.jar', $this->notified[0]['body']);
    }

    public function test_a_plugin_with_no_build_holds(): void
    {
        $this->jars = ['Essentials.jar'];
        ServerMod::query()->forceCreate(['server_uuid' => $this->server->uuid, 'source' => 'modrinth', 'source_id' => 'ess', 'mod_name' => 'EssentialsX', 'installed_version' => '1', 'target_filename' => 'Essentials.jar']);
        $this->pluginBuilds = ['ess' => false];
        $this->assertHeld('plugins');
        $this->assertStringContainsString('EssentialsX', $this->notified[0]['body']);
    }

    public function test_a_plugin_with_a_build_does_not_hold(): void
    {
        $this->jars = ['Essentials.jar'];
        ServerMod::query()->forceCreate(['server_uuid' => $this->server->uuid, 'source' => 'modrinth', 'source_id' => 'ess', 'mod_name' => 'EssentialsX', 'installed_version' => '1', 'target_filename' => 'Essentials.jar']);
        $this->tick();
        $this->assertNotNull($this->setting()->auto_upgrade_run);
    }

    public function test_an_unreadable_plugins_folder_holds(): void
    {
        $this->jarsError = new \RuntimeException('wings down');
        $this->assertHeld('plugins');
    }

    public function test_a_failed_preflight_holds(): void
    {
        $this->preflightBlockers = ['This version needs Java 21.'];
        $this->assertHeld('preflight');
    }

    public function test_a_pinned_jar_with_no_replacement_holds(): void
    {
        $this->dlPath = 'https://old.test/1.21.4.jar';
        foreach ($this->builds['1.21.5'] as $i => $b) {
            $this->builds['1.21.5'][$i]['download_url'] = null;
        }
        $this->assertHeld('download');
    }

    public function test_a_backup_at_the_limit_holds_and_notifies(): void
    {
        $this->backupResult = RotationResult::skipped('no_evictable_at_limit');
        $this->assertHeld('backup');
        $this->assertCount(1, $this->notified);
    }

    public function test_a_throttled_backup_tries_again_quietly(): void
    {
        $this->backupResult = RotationResult::skipped('throttled');
        $this->assertHeld('backup');
        $this->assertSame([], $this->notified);
    }

    public function test_a_failed_backup_changes_nothing(): void
    {
        $this->tick();
        $this->finishBackup(false);
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->applied);
        $this->assertSame('backup', $this->code());
        $this->assertNull($this->setting()->auto_upgrade_paused_at, 'nothing broke, so nothing to pause for');
    }

    public function test_a_player_joining_during_the_backup_stops_it_and_the_backup_is_reused(): void
    {
        $this->tick();
        $this->finishBackup();
        $this->status = ['players' => ['online' => 1]];
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->applied);
        $this->assertNull($this->setting()->auto_upgrade_run);

        $this->status = ['players' => ['online' => 0], 'version' => ['name' => 'Paper 1.21.5']];
        $this->backupResult = RotationResult::failed('should_not_be_called');
        $this->tick();
        $this->assertSame('b-1', $this->setting()->auto_upgrade_run['backup_uuid']);
    }

    public function test_a_manual_switch_during_the_backup_cancels(): void
    {
        $this->tick();
        $this->setting()->forceFill(['game_version' => '1.20.6'])->save();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->assertSame([], $this->applied);
        $this->assertSame('cancelled', $this->code());
    }

    /** Runs to the boot phase, with the server not answering. */
    private function toBooting(): void
    {
        $this->tick();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->state = ['power' => 'offline', 'install' => null];
        $this->status = null;
        app(AutoUpgradeService::class)->tick();
    }

    public function test_a_failed_boot_pauses_names_the_backup_and_does_not_restore(): void
    {
        $this->toBooting();
        app(AutoUpgradeService::class)->tick();
        $this->assertNotNull($this->setting()->auto_upgrade_run, 'gets its time to start');

        $this->travel(16)->minutes();
        app(AutoUpgradeService::class)->tick();
        $s = $this->setting();
        $this->assertNull($s->auto_upgrade_run);
        $this->assertNotNull($s->auto_upgrade_paused_at);
        $this->assertSame('error', end($this->notified)['level']);
        $this->assertStringContainsString('Pre-upgrade', end($this->notified)['body']);

        $this->status = ['players' => ['online' => 0], 'version' => ['name' => 'Paper 1.21.5']];
        $this->state = ['power' => 'running', 'install' => null];
        $count = count($this->applied);
        $this->optIn(['game_version' => '1.21.4']);
        $this->setting()->forceFill(['auto_upgrade_hour' => BackupSchedule::now()->hour])->save();
        $this->tick();
        $this->assertCount($count, $this->applied, 'stays paused');
        $this->assertNull($this->setting()->auto_upgrade_run);

        app(AutoUpgradeService::class)->configure($this->server, true, 'line', BackupSchedule::now()->hour);
        $this->assertNull($this->setting()->auto_upgrade_paused_at, 'turning it on again clears the pause');
    }

    public function test_the_wrong_version_coming_up_counts_as_a_failure(): void
    {
        $this->toBooting();
        $this->status = ['players' => ['online' => 0], 'version' => ['name' => 'Paper 1.21.4']];
        app(AutoUpgradeService::class)->tick();
        $this->assertNotNull($this->setting()->auto_upgrade_paused_at);
    }

    public function test_a_plugin_update_that_fails_is_reported_but_the_server_still_starts(): void
    {
        $this->tick();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        ServerMod::query()->forceCreate(['server_uuid' => $this->server->uuid, 'source' => 'modrinth', 'source_id' => 'ess', 'mod_name' => 'EssentialsX', 'installed_version' => '1', 'target_filename' => 'Essentials.jar']);
        $this->updateError = true;
        $this->state = ['power' => 'offline', 'install' => null];
        app(AutoUpgradeService::class)->tick();
        $this->assertSame(['start'], $this->signals);
        $this->assertStringContainsString('Still on the old build: EssentialsX', $this->setting()->auto_upgrade_run['plugins']);
    }

    public function test_a_plugin_already_on_the_build_is_left_alone(): void
    {
        $this->tick();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        ServerMod::query()->forceCreate(['server_uuid' => $this->server->uuid, 'source' => 'modrinth', 'source_id' => 'ess', 'mod_name' => 'EssentialsX', 'installed_version' => '2', 'target_filename' => 'Essentials.jar']);
        $this->state = ['power' => 'offline', 'install' => null];
        app(AutoUpgradeService::class)->tick();
        $this->assertNotContains('plugins', $this->order);
    }

    public function test_a_failed_reinstall_pauses(): void
    {
        $this->tick();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->state = ['power' => 'offline', 'install' => 'reinstall_failed'];
        app(AutoUpgradeService::class)->tick();
        $this->assertNotNull($this->setting()->auto_upgrade_paused_at);
        $this->assertSame([], $this->signals);
    }

    public function test_an_upgrade_resumes_from_stored_state_with_a_new_service(): void
    {
        $this->tick();
        $this->finishBackup();
        app(AutoUpgradeService::class)->tick();
        $this->app->forgetInstance(AutoUpgradeService::class);
        $this->state = ['power' => 'offline', 'install' => null];
        $this->app->make(AutoUpgradeService::class)->tick();
        $this->assertSame('booting', $this->setting()->auto_upgrade_run['phase']);
    }

    public function test_the_concurrency_limit_holds_new_starts_back(): void
    {
        config(['serversuite.auto_upgrades.max_concurrent' => 1]);
        $other = $this->makeServer();
        ModManagerSetting::forServer($other->uuid)->forceFill(['loader' => 'paper', 'game_version' => '1.21.4', 'auto_upgrade_enabled' => true, 'auto_upgrade_hour' => BackupSchedule::now()->hour])->save();
        $this->tick();
        $running = ModManagerSetting::query()->whereNotNull('auto_upgrade_run')->count();
        $this->assertSame(1, $running);
    }

    public function test_line_rules(): void
    {
        $this->assertSame('1.21', AutoUpgradePolicy::line('1.21.4'));
        $this->assertSame('26.1', AutoUpgradePolicy::line('26.1.2'));
        $list = ['26.1.1', '26.1', '1.21.11', '1.21.9', '1.21.4', '1.21.3', '1.21.5-rc1', '1.20.6'];
        $this->assertSame(['1.21.11', '1.21.9'], AutoUpgradePolicy::candidates('1.21.4', $list, 'line'), 'numeric order, no pre-releases, no downgrades');
        $this->assertSame(['26.1.1', '26.1', '1.21.11', '1.21.9'], AutoUpgradePolicy::candidates('1.21.4', $list, 'latest'));
        $this->assertSame(['26.1.1'], AutoUpgradePolicy::candidates('26.1', $list, 'line'));
        $this->assertSame('26.1.1', AutoUpgradePolicy::newerLine('1.21.11', $list));
        $this->assertNull(AutoUpgradePolicy::newerLine('26.1', $list));
    }
}
