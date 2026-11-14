<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Jobs\ApplyModUpdatesJob;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ServerModPendingUpdate;
use ServerSuite\Services\CrashHelpService;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\Notifier;
use ServerSuite\Services\PreChangeBackup;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\RepositoryManager;

/** #120: the scheduled check asks what an update installs, and --apply installs in the background. */

function ucaVersion(string $n): ModVersion
{
    return new ModVersion(versionId: "v{$n}", versionNumber: $n, loaders: ['fabric'], gameVersions: ['1.21.4'],
        downloadUrl: "https://x.test/{$n}.jar", filename: "{$n}.jar", fileSize: 1, changelog: null);
}

beforeEach(function () {
    panelTables();
    $this->uuid = 'srv-uca';
    \Pterodactyl\Models\Server::query()->forceCreate(['uuid' => $this->uuid]);
    ModManagerSetting::forServer($this->uuid)->forceFill(['loader' => 'fabric', 'game_version' => '1.21.4', 'auto_update_checks' => true])->save();
    // Installed from a build whose first listed game version was older.
    $this->mod = ServerMod::query()->create([
        'server_uuid' => $this->uuid, 'source' => 'modrinth', 'source_id' => 'SODIUM', 'mod_name' => 'Sodium',
        'installed_version' => '1', 'target_filename' => 'sodium-1.jar', 'install_path' => '/mods',
        'loader' => 'fabric', 'game_version' => '1.20.1',
    ]);

    $this->asked = [];
    $driver = Mockery::mock(RepositoryContract::class);
    $driver->shouldReceive('isAvailable')->andReturn(true);
    $driver->shouldReceive('latestVersion')->andReturnUsing(function ($id, $loader, $version) {
        $this->asked[] = [$id, $loader, $version];

        return $version === '1.21.4' ? ucaVersion('2') : ucaVersion('1.20-only');
    });
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('driver')->andReturn($driver);
    $this->app->instance(RepositoryManager::class, $repos);

    $this->notified = [];
    $notifier = Mockery::mock(Notifier::class);
    $notifier->shouldReceive('notify')->andReturnUsing(function ($uuid, $event, $title, $body = '', $level = 'info') {
        $this->notified[] = compact('title', 'body', 'level');
    });
    $this->app->instance(Notifier::class, $notifier);
    $this->app->instance(CrashHelpService::class, Mockery::mock(CrashHelpService::class)->shouldIgnoreMissing());
});

it('checks against the server game version, the same lookup an update uses', function () {
    $r = app(ModManagerService::class)->checkUpdates((object) ['uuid' => $this->uuid]);

    expect($r['updates'])->toBe(1)
        ->and($this->asked)->toBe([['SODIUM', 'fabric', '1.21.4']])
        ->and($this->mod->fresh()->pendingUpdate->available_version)->toBe('2')
        ->and(app(ModManagerService::class)->latestFor($this->mod, (object) ['uuid' => $this->uuid])->versionNumber)->toBe('2');
});

it('keeps a dismissed update dismissed until a newer version appears', function () {
    ServerModPendingUpdate::query()->create(['server_mod_id' => $this->mod->id, 'available_version' => '2', 'dismissed' => true]);
    app(ModManagerService::class)->checkUpdates((object) ['uuid' => $this->uuid]);
    expect($this->mod->fresh()->pendingUpdate->dismissed)->toBeTrue();

    $this->mod->pendingUpdate()->update(['available_version' => '1.5']);
    app(ModManagerService::class)->checkUpdates((object) ['uuid' => $this->uuid]);
    expect($this->mod->fresh()->pendingUpdate->dismissed)->toBeFalse()
        ->and($this->mod->fresh()->pendingUpdate->available_version)->toBe('2');
});

it('clears the flag on pack mods and on mods that are up to date', function () {
    $pack = ServerMod::query()->create(['server_uuid' => $this->uuid, 'source' => 'modrinth', 'source_id' => 'PACK', 'mod_name' => 'Pack',
        'installed_version' => '1', 'target_filename' => 'p.jar', 'install_path' => '/mods', 'modpack_id' => 4]);
    ServerModPendingUpdate::query()->create(['server_mod_id' => $pack->id, 'available_version' => '9']);
    $this->mod->update(['installed_version' => '2']);
    ServerModPendingUpdate::query()->create(['server_mod_id' => $this->mod->id, 'available_version' => '2']);

    app(ModManagerService::class)->checkUpdates((object) ['uuid' => $this->uuid]);

    expect(ServerModPendingUpdate::query()->count())->toBe(0);
});

it('queues one background install per server with --apply, none without', function () {
    Queue::fake();

    $this->artisan('serversuite:check-updates')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->artisan('serversuite:check-updates', ['--apply' => true])->assertSuccessful();
    $this->artisan('serversuite:check-updates', ['--apply' => true])->assertSuccessful();
    Queue::assertPushed(ApplyModUpdatesJob::class, 1);
    Queue::assertPushed(ApplyModUpdatesJob::class, fn ($j) => $j->serverUuid === $this->uuid && $j->backupUuid === null);
});

describe('the apply job', function () {
    beforeEach(function () {
        Cache::put(ApplyModUpdatesJob::lockKey($this->uuid), 1, 600);
        $this->applied = 0;
        $mods = Mockery::mock(ModManagerService::class);
        $mods->shouldReceive('serverFor')->andReturn((object) ['uuid' => $this->uuid]);
        $mods->shouldReceive('updateAll')->andReturnUsing(function () {
            $this->applied++;

            return ['updated' => [['mod_name' => 'Sodium', 'installed_version' => '2']], 'failed' => []];
        });
        $this->app->instance(ModManagerService::class, $mods);

        $this->backupState = 'running';
        $this->pre = Mockery::mock(PreChangeBackup::class);
        $this->pre->shouldReceive('state')->andReturnUsing(fn () => $this->backupState);
        $this->app->instance(PreChangeBackup::class, $this->pre);
        $this->run = fn (ApplyModUpdatesJob $job) => $this->app->call([$job, 'handle']);
    });

    it('starts the backup and comes back later instead of waiting', function () {
        Queue::fake();
        $this->pre->shouldReceive('start')->once()->andReturn(['status' => 'started', 'uuid' => 'b1', 'name' => 'Pre-mod-change']);

        ($this->run)(new ApplyModUpdatesJob($this->uuid));

        expect($this->applied)->toBe(0)->and(Cache::has(ApplyModUpdatesJob::lockKey($this->uuid)))->toBeTrue();
        Queue::assertPushed(ApplyModUpdatesJob::class, fn ($j) => $j->backupUuid === 'b1' && $j->deadline > time() && $j->delay !== null);
    });

    it('checks again while the backup runs, installs once it is done', function () {
        Queue::fake();
        ($this->run)(new ApplyModUpdatesJob($this->uuid, 'b1', 'Pre-mod-change', time() + 600));
        expect($this->applied)->toBe(0);
        Queue::assertPushed(ApplyModUpdatesJob::class, 1);

        $this->backupState = 'done';
        ($this->run)(new ApplyModUpdatesJob($this->uuid, 'b1', 'Pre-mod-change', time() + 600));

        expect($this->applied)->toBe(1)
            ->and($this->notified[0]['title'])->toBe('1 mod update(s) installed')
            ->and(Cache::has(ApplyModUpdatesJob::lockKey($this->uuid)))->toBeFalse();
    });

    it('installs nothing when the backup fails or runs past the deadline', function () {
        Queue::fake();
        ($this->run)(new ApplyModUpdatesJob($this->uuid, 'b1', 'Pre-mod-change', time() - 1));
        $this->backupState = 'failed';
        ($this->run)(new ApplyModUpdatesJob($this->uuid, 'b1', 'Pre-mod-change', time() + 600));
        $this->pre->shouldReceive('start')->andReturn(['status' => 'failed', 'message' => 'throttled']);
        ($this->run)(new ApplyModUpdatesJob($this->uuid));

        expect($this->applied)->toBe(0)
            ->and(array_column($this->notified, 'level'))->toBe(['warn', 'warn', 'warn'])
            ->and($this->notified[0]['body'])->toContain('did not finish in time')
            ->and($this->notified[2]['body'])->toContain('throttled')
            ->and(Cache::has(ApplyModUpdatesJob::lockKey($this->uuid)))->toBeFalse();
        Queue::assertNothingPushed();
    });

    it('installs straight away when the server never backs up or has no backups', function () {
        ModManagerSetting::forServer($this->uuid)->update(['pre_change_backup' => 'never']);
        ($this->run)(new ApplyModUpdatesJob($this->uuid));
        ModManagerSetting::forServer($this->uuid)->update(['pre_change_backup' => 'ask']);
        $this->pre->shouldReceive('start')->andReturn(['status' => 'disabled']);
        ($this->run)(new ApplyModUpdatesJob($this->uuid));

        expect($this->applied)->toBe(2);
    });

    it('leaves a suspended server alone', function () {
        $this->app->instance(ModManagerService::class, Mockery::mock(ModManagerService::class, function ($m) {
            $m->shouldReceive('serverFor')->andReturn((object) ['uuid' => $this->uuid, 'status' => 'suspended']);
            $m->shouldNotReceive('updateAll');
        }));
        ($this->run)(new ApplyModUpdatesJob($this->uuid));

        expect($this->notified[0]['body'])->toContain('suspended');
    });
});
