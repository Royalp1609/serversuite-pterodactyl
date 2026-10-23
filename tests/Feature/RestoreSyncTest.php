<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Jobs\SyncAfterRestoreJob;
use ServerSuite\Models\BackupModList;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Modpack;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ServerModPendingUpdate;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Services\ReconciliationService;
use ServerSuite\Services\RestoreSyncService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #60: restoring a backup brings the mod list back in line. Server Suite
 * saves the list with each backup it takes and puts it back when a restore
 * finishes, however the restore was started.
 */

beforeEach(function () {
    panelTables();
    $this->server = Server::query()->create(['uuid' => 'srv-r', 'name' => 'R']);
    ModManagerSetting::forServer('srv-r')->forceFill(['loader' => 'fabric', 'game_version' => '1.21.1'])->save();

    $this->wings = new FakeWings();
    app()->instance(DaemonFileClient::class, $this->wings);
    $this->ops = new FakePanelServerOps();
    app()->instance(PanelServerOps::class, $this->ops);
});

function rsMod(object $t, string $id, ?int $packId = null): ServerMod
{
    $t->wings->put("/mods/{$id}.jar", "jar {$id}");

    return ServerMod::query()->create([
        'server_uuid' => 'srv-r', 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => "Mod {$id}",
        'installed_version' => '1.0', 'target_filename' => "{$id}.jar", 'install_path' => '/mods', 'modpack_id' => $packId,
    ]);
}

function rsDrop(object $t, string $id): void
{
    ServerMod::forServer('srv-r')->where('source_id', $id)->delete();
    unset($t->wings->files["/mods/{$id}.jar"]);
}

/** A Server Suite backup; returns its uuid and the files it holds. @return array{0: string, 1: array<string, string>} */
function rsBackup(object $t): array
{
    $created = app(BackupRotationService::class)->backupNow('srv-r', 'Pre-change')->created;

    return [$created->uuid, $t->wings->files];
}

/** What Wings does: put the backup's files back, over or instead of what's there. */
function rsRestoreFiles(object $t, array $files, bool $truncate): void
{
    $t->wings->files = $truncate ? $files : array_merge($t->wings->files, $files);
}

function rsMods(): array
{
    return ServerMod::forServer('srv-r')->orderBy('source_id')->pluck('source_id')->all();
}

it('saves the mod list with every backup it takes, and forgets it with the backup', function () {
    rsMod($this, 'a');
    rsMod($this, 'b')->forceFill(['disabled_at' => now()])->save();
    [$uuid] = rsBackup($this);

    $saved = BackupModList::query()->where('backup_uuid', $uuid)->sole();
    expect(array_column($saved->mods, 'source_id'))->toBe(['a', 'b'])
        ->and($saved->mods[1]['disabled_at'])->not->toBeNull()
        ->and($saved->settings)->toBe(['loader' => 'fabric', 'game_version' => '1.21.1', 'install_path' => null]);

    // Deleted outside Server Suite: the next backup prunes it.
    $this->ops->backups['srv-r'] = [];
    [$next] = rsBackup($this);
    expect(BackupModList::query()->pluck('backup_uuid')->all())->toBe([$next]);

    app(RestoreSyncService::class)->forget($next);
    expect(BackupModList::query()->count())->toBe(0);
});

it('puts back a backup that had fewer mods', function () {
    rsMod($this, 'a');
    [$uuid, $files] = rsBackup($this);
    rsMod($this, 'b');

    rsRestoreFiles($this, $files, true);
    $r = app(RestoreSyncService::class)->apply($this->server, $uuid, true);

    expect(rsMods())->toBe(['a'])
        ->and($r['back'])->toBe([])
        ->and($r['gone'])->toBe(['Mod b'])
        ->and($r['saved_list'])->toBeTrue()
        ->and(app(RestoreSyncService::class)->lastResult('srv-r')['backup']['uuid'])->toBe($uuid);
});

it('puts back a backup that had more mods, with their versions', function () {
    rsMod($this, 'a');
    rsMod($this, 'b');
    [$uuid, $files] = rsBackup($this);
    rsDrop($this, 'b');
    ServerMod::forServer('srv-r')->where('source_id', 'a')->update(['installed_version' => '2.0']);
    ServerModPendingUpdate::query()->create(['server_mod_id' => ServerMod::forServer('srv-r')->value('id'), 'available_version' => '3.0']);

    rsRestoreFiles($this, $files, true);
    $r = app(RestoreSyncService::class)->apply($this->server, $uuid, true);

    expect(rsMods())->toBe(['a', 'b'])
        ->and(ServerMod::forServer('srv-r')->where('source_id', 'a')->value('installed_version'))->toBe('1.0')
        ->and(ServerModPendingUpdate::query()->count())->toBe(0, 'update badges are recomputed')
        ->and($r['back'])->toBe(['Mod b']);
});

it('keeps mods added after the backup when the restore did not delete files first', function () {
    rsMod($this, 'a');
    [$uuid, $files] = rsBackup($this);
    rsMod($this, 'b');

    rsRestoreFiles($this, $files, false);
    $r = app(RestoreSyncService::class)->apply($this->server, $uuid, false);

    expect(rsMods())->toBe(['a', 'b'])
        ->and($r['gone'])->toBe([]);
});

it('puts the modpack and the loader settings back', function () {
    $pack = Modpack::query()->create(['server_uuid' => 'srv-r', 'project_id' => 'P', 'name' => 'Pack', 'version_number' => 'v1', 'status' => 'installed', 'files' => [['path' => 'mods/p.jar']]]);
    rsMod($this, 'p', $pack->id);
    [$uuid, $files] = rsBackup($this);

    // A switch to another pack and version after the backup.
    $pack->forceFill(['project_id' => 'Q', 'name' => 'Other', 'version_number' => 'v9'])->save();
    ModManagerSetting::forServer('srv-r')->forceFill(['game_version' => '1.20.1'])->save();
    rsDrop($this, 'p');

    rsRestoreFiles($this, $files, true);
    app(RestoreSyncService::class)->apply($this->server, $uuid, true);

    $pack = Modpack::forServer('srv-r');
    expect([$pack->project_id, $pack->name, $pack->version_number, $pack->status])->toBe(['P', 'Pack', 'v1', 'installed'])
        ->and(ServerMod::forServer('srv-r')->value('modpack_id'))->toBe($pack->id)
        ->and(ModManagerSetting::forServer('srv-r')->game_version)->toBe('1.21.1');
});

it('fixes only the clear cases for a backup with no saved list', function () {
    rsMod($this, 'a');
    rsMod($this, 'gone');
    unset($this->wings->files['/mods/gone.jar']);
    $this->wings->put('/mods/found.jar', 'jar found');
    $this->wings->put('/mods/unknown.jar', 'jar ?');

    $recon = Mockery::mock(ReconciliationService::class);
    $recon->shouldReceive('reconcile')->andReturn(['directory' => '/mods', 'missing' => [], 'untracked' => [
        ['filename' => 'found.jar', 'size' => 9, 'identified' => ['confidence' => 'exact', 'source' => 'modrinth', 'mod_id' => 'found', 'version_id' => 'v']],
        ['filename' => 'unknown.jar', 'size' => 5, 'identified' => ['confidence' => 'fuzzy', 'source' => 'modrinth', 'mod_id' => 'x', 'version_id' => null]],
    ]]);
    $recon->shouldReceive('adopt')->once()->withArgs(fn ($s, $file) => $file === 'found.jar')->andReturnUsing(fn () => ServerMod::query()->create([
        'server_uuid' => 'srv-r', 'source' => 'modrinth', 'source_id' => 'found', 'mod_name' => 'Mod found', 'installed_version' => 'v', 'target_filename' => 'found.jar',
    ]));
    app()->instance(ReconciliationService::class, $recon);

    $r = app(RestoreSyncService::class)->apply($this->server, 'not-ours', true);

    expect(rsMods())->toBe(['a', 'found'])
        ->and($r['saved_list'])->toBeFalse()
        ->and($r['forgotten'])->toBe(['Mod gone'])
        ->and($r['adopted'])->toBe(['Mod found'])
        ->and($r['untracked'])->toBe(1, 'unknown.jar is left for Reconcile');
});

it('queues the sync when a restore finishes, however it was started', function () {
    Queue::fake();
    $this->server->forceFill(['status' => 'restoring_backup'])->save();
    Queue::assertNothingPushed();

    $this->server->forceFill(['status' => null])->save();
    Queue::assertPushed(SyncAfterRestoreJob::class, fn ($job) => $job->serverUuid === 'srv-r');

    // Other status changes don't.
    Queue::fake();
    $this->server->forceFill(['status' => 'installing'])->save();
    $this->server->forceFill(['status' => null])->save();
    Queue::assertNotPushed(SyncAfterRestoreJob::class);
});

/** The panel's activity tables, and a restore logged the way its client API logs one. */
function rsPanelRestore(object $t, string $backupUuid, bool $truncate, ?string $outcome = 'server:backup.restore-complete'): void
{
    if (! Schema::hasTable('activity_logs')) {
        Schema::create('activity_logs', function ($table) {
            $table->id();
            $table->string('event');
            $table->text('properties')->nullable();
            $table->timestamp('timestamp');
        });
        Schema::create('activity_log_subjects', function ($table) {
            $table->id();
            $table->unsignedBigInteger('activity_log_id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
        });
        Schema::create('backups', function ($table) {
            $table->id();
            $table->string('uuid');
        });
    }
    $backupId = DB::table('backups')->insertGetId(['uuid' => $backupUuid]);
    $log = function (string $event, array $props) use ($t, $backupId) {
        $id = DB::table('activity_logs')->insertGetId(['event' => $event, 'properties' => json_encode($props), 'timestamp' => now()]);
        DB::table('activity_log_subjects')->insert([
            ['activity_log_id' => $id, 'subject_type' => 'backup', 'subject_id' => $backupId],
            ['activity_log_id' => $id, 'subject_type' => 'server', 'subject_id' => $t->server->id],
        ]);
    };
    $log('server:backup.restore', ['name' => 'x', 'truncate' => $truncate]);
    if ($outcome !== null) {
        $log($outcome, ['name' => 'x']);
    }
}

it("finds a restore started on the panel's own Backups page in its activity log", function () {
    rsMod($this, 'a');
    [$uuid, $files] = rsBackup($this);
    rsMod($this, 'b');
    rsRestoreFiles($this, $files, true);
    rsPanelRestore($this, $uuid, true);

    (new SyncAfterRestoreJob('srv-r'))->handle(app(RestoreSyncService::class));

    expect(rsMods())->toBe(['a']);
});

it('uses the restore our Backups tab started, with its truncate choice', function () {
    rsMod($this, 'a');
    [$uuid, $files] = rsBackup($this);
    rsMod($this, 'b');
    rsRestoreFiles($this, $files, false);
    app(RestoreSyncService::class)->noteRestore('srv-r', $uuid, false);

    $r = app(RestoreSyncService::class)->completed($this->server);

    expect($r['backup']['uuid'])->toBe($uuid)
        ->and($r['truncate'])->toBeFalse()
        ->and(rsMods())->toBe(['a', 'b']);
});

it('leaves the list alone when the restore failed', function () {
    rsMod($this, 'a');
    [$uuid] = rsBackup($this);
    rsMod($this, 'b');
    rsPanelRestore($this, $uuid, true, 'server.backup.restore-failed');

    expect(app(RestoreSyncService::class)->completed($this->server))->toBeNull()
        ->and(rsMods())->toBe(['a', 'b']);
});
