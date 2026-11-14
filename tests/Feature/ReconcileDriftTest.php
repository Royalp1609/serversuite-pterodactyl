<?php

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Services\ReconciliationService;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Tests\Fakes\FakeWings;

/** #127: reconcile's missing list, and adopting a mod that is already tracked. */

function rdMod(string $id, string $file, array $extra = []): ServerMod
{
    return ServerMod::query()->create($extra + ['server_uuid' => 'srv-rd', 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => "Mod {$id}",
        'installed_version' => '1', 'target_filename' => $file, 'install_path' => '/mods']);
}

beforeEach(function () {
    Cache::flush();
    ModManagerSetting::forServer('srv-rd')->forceFill(['loader' => 'fabric', 'install_path' => '/mods'])->save();
    $this->wings = new FakeWings();
    app()->instance(DaemonFileClient::class, $this->wings);

    $this->lookups = 0;
    $driver = Mockery::mock(RepositoryContract::class)->shouldIgnoreMissing();
    $driver->shouldReceive('get')->andReturnUsing(fn ($id) => new ModResult(source: 'modrinth', id: $id, name: "Mod {$id}", slug: strtolower($id),
        description: '', author: '', downloads: 0, iconUrl: null, pageUrl: null, loaders: ['fabric'], gameVersions: [], dependencies: []));
    $driver->shouldReceive('lookupByHash')->andReturnUsing(function () {
        $this->lookups++;

        return null;
    });
    $driver->shouldReceive('search')->andReturn([]);
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('driver')->andReturn($driver);
    app()->instance(RepositoryManager::class, $repos);
    $this->server = (object) ['uuid' => 'srv-rd'];
});

it('does not list a disabled mod, or a mod tracked in another folder, as missing', function () {
    rdMod('DIS', 'dis-1.0.jar', ['disabled_at' => now()]);
    rdMod('PACK', 'pack.jar', ['install_path' => '/config/pack']);
    rdMod('GONE', 'gone.jar');
    $this->wings->put('/mods/dis-1.0.jar.disabled', 'x');

    $missing = array_column(app(ReconciliationService::class)->reconcile($this->server)['missing'], 'target_filename');

    expect($missing)->toBe(['gone.jar']);
});

it('refuses to adopt a second file of a mod that is already tracked, with a reason', function () {
    rdMod('SODIUM', 'sodium-0.6.jar');
    $this->wings->put('/mods/sodium-0.6.jar', 'a');
    $this->wings->put('/mods/sodium-0.5.jar', 'b');

    expect(fn () => app(ReconciliationService::class)->adopt($this->server, 'sodium-0.5.jar', 'modrinth', 'SODIUM', null, 1))
        ->toThrow(InvalidArgumentException::class, 'Mod SODIUM is already tracked as sodium-0.6.jar');
    expect(ServerMod::query()->count())->toBe(1);
});

it('adopts without identifying every file again, and remembers identifications', function () {
    foreach (['a.jar', 'b.jar', 'c.jar'] as $f) {
        $this->wings->put("/mods/{$f}", $f);
    }
    $svc = app(ReconciliationService::class);

    $svc->adopt($this->server, 'a.jar', 'manual', null, null, 1);
    expect($this->lookups)->toBe(0);

    $svc->reconcile($this->server);
    $first = $this->lookups;
    $svc->reconcile($this->server);
    expect($first)->toBeGreaterThan(0)->and($this->lookups)->toBe($first);
});
