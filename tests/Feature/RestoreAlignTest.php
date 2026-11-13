<?php

use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Models\BackupModList;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #119: after a restore the server is realigned from the record Server Suite
 * saved with the backup, never from the backup's (user-editable) name.
 */
beforeEach(function () {
    panelTables();
    Http::preventStrayRequests();
    Http::fake();
    $this->paper   = Egg::query()->create(['name' => 'Paper', 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]);
    $this->bedrock = Egg::query()->create(['name' => 'Vanilla Bedrock', 'docker_images' => ['Bedrock' => 'ghcr.io/parkervcp/yolks:debian']]);
    $this->fabric  = Egg::query()->create(['name' => 'Fabric', 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]);
    Pterodactyl\Models\EggVariable::query()->create(['egg_id' => $this->fabric->id, 'env_variable' => 'MC_VERSION', 'default_value' => 'latest']);
    $this->server = Server::query()->create(['uuid' => 'srv-al', 'name' => 'AL', 'egg_id' => $this->paper->id]);
    ModManagerSetting::forServer('srv-al')->forceFill(['loader' => 'paper', 'game_version' => '1.21.4'])->save();

    app()->instance(DaemonFileClient::class, new FakeWings());
    $this->ops = new FakePanelServerOps();
    app()->instance(PanelServerOps::class, $this->ops);
});

function alRecord(string $backup, ?string $loader, ?string $version): void
{
    BackupModList::query()->create(['backup_uuid' => $backup, 'server_uuid' => 'srv-al', 'mods' => [],
        'settings' => ['loader' => $loader, 'game_version' => $version, 'install_path' => null]]);
}

it('ignores a backup with no record, whatever its name says', function () {
    $this->ops->backups['srv-al'] = [];   // name is never read now

    expect(app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-panel'))->toBeNull()
        ->and($this->ops->log)->toBe([]);
});

it('does nothing when the recorded loader and version are the current ones', function () {
    alRecord('b-same', 'paper', '1.21.4');

    expect(app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-same'))->toBeNull()
        ->and($this->ops->log)->toBe([], 'no BUILD_NUMBER reset on a same-version restore');
});

it('pins the recorded version for a same-loader restore', function () {
    alRecord('b-old', 'paper', '1.20.6');

    $r = app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-old');

    expect($r['version'])->toBe('1.20.6')
        ->and($this->ops->log[0][0])->toBe('set_vars')
        ->and($this->ops->log[0][2]['MINECRAFT_VERSION'])->toBe('1.20.6')
        ->and(ModManagerSetting::forServer('srv-al')->game_version)->toBe('1.20.6');
});

it('switches to the offered egg for a recorded cross-loader restore', function () {
    alRecord('b-fab', 'fabric', '1.21.1');

    $r = app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-fab');

    expect($r['loader_switched'] ?? false)->toBeTrue()
        ->and($r['egg']['id'])->toBe($this->fabric->id)
        ->and($this->ops->log[0][0])->toBe('change_egg')
        ->and($this->ops->log[0][2])->toBe($this->fabric->id);
});

it('never picks a Bedrock egg for the vanilla loader', function () {
    alRecord('b-van', 'vanilla', '1.21.1');

    app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-van');

    $eggChanges = array_filter($this->ops->log, fn ($l) => $l[0] === 'change_egg');
    expect($eggChanges)->toBe([]);
});

it('ignores a record whose version is not a release number', function () {
    alRecord('b-snap', 'paper', '25w14a');

    expect(app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-snap'))->toBeNull()
        ->and($this->ops->log)->toBe([]);
});

it('only uses the record for this server', function () {
    BackupModList::query()->create(['backup_uuid' => 'b-other', 'server_uuid' => 'srv-x', 'mods' => [],
        'settings' => ['loader' => 'fabric', 'game_version' => '1.20.1', 'install_path' => null]]);

    expect(app(VersionSwitchService::class)->alignToBackupVersion($this->server, 'b-other'))->toBeNull();
});
