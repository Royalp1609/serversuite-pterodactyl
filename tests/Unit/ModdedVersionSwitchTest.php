<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\McJarsService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\Data\LoaderDetectionResult;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/** Versions tab on Forge, NeoForge, Fabric and Quilt: lists from MCJars, the loader build pinned. */

function mvsServer(): object
{
    return (object) ['uuid' => 'srv-mvs', 'skip_scripts' => false, 'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'egg' => (object) ['docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21', 'Java 25' => 'ghcr.io/pterodactyl/yolks:java_25']]];
}

function mvsFakeMcJars(): void
{
    Http::fake([
        'mcjars.app/api/v2/builds/FORGE/1.21.1' => Http::response(['builds' => [
            ['projectVersionId' => '52.1.21', 'experimental' => false],
            ['projectVersionId' => '52.1.20', 'experimental' => false],
        ]]),
        'mcjars.app/api/v2/builds/NEOFORGE/26.1' => Http::response(['builds' => [
            ['projectVersionId' => '26.1.0.19-beta', 'experimental' => true],
        ]]),
        'mcjars.app/api/v2/builds/FORGE' => Http::response(['builds' => [
            '1.20.1' => ['type' => 'RELEASE'], '1.21.1' => ['type' => 'RELEASE'], '1.21.10' => ['type' => 'RELEASE'],
            '26.1' => ['type' => 'RELEASE'], '1.21.9-pre1' => ['type' => 'SNAPSHOT'], '24w10a' => ['type' => 'SNAPSHOT'],
        ]]),
        'mcjars.app/api/v2/builds/NEOFORGE' => Http::response(['builds' => ['26.1' => ['type' => 'RELEASE'], '1.21.1' => ['type' => 'RELEASE']]]),
        'mcjars.app/api/v2/builds/FABRIC' => Http::response(['builds' => ['1.21.8' => ['type' => 'RELEASE'], '1.21.1' => ['type' => 'RELEASE']]]),
        '*' => Http::response([], 404),
    ]);
}

beforeEach(function () {
    config(['serversuite.versions.mcjars' => true]);
    $this->ops = new FakePanelServerOps();
    $this->ops->states['srv-mvs'] = ['power' => 'offline', 'install' => null];
    app()->instance(PanelServerOps::class, $this->ops);
    app()->instance(DaemonFileClient::class, Mockery::mock(DaemonFileClient::class)->shouldIgnoreMissing());

    $this->loader = 'forge';
    $this->mc = '1.20.1';
    $detect = Mockery::mock(LoaderDetectionService::class)->shouldIgnoreMissing();
    foreach (['detectDetailed', 'eggLoader'] as $m) {
        $detect->shouldReceive($m)->andReturnUsing(fn () => new LoaderDetectionResult($this->loader, 90, 'egg', $this->mc));
    }
    $detect->shouldReceive('detect')->andReturnUsing(fn () => $this->loader);
    app()->instance(LoaderDetectionService::class, $detect);
    mvsFakeMcJars();
});

it('lists release versions newest first, without snapshots', function () {
    expect(app(ServerVersionService::class)->available('forge'))
        ->toBe(['supported' => true, 'loader' => 'forge', 'versions' => ['26.1', '1.21.10', '1.21.1', '1.20.1']])
        ->and(app(ServerVersionService::class)->available('fabric')['versions'])->toBe(['1.21.8', '1.21.1']);
});

it('lists nothing when MCJars is turned off', function () {
    config(['serversuite.versions.mcjars' => false]);
    expect(app(McJarsService::class)->releases('forge'))->toBe([]);
});

it('pins the exact Forge build as the egg wants it, and a NeoForge beta when that is all there is', function () {
    $svc = app(ServerVersionService::class);

    expect($svc->loaderPin('forge', '1.21.1'))->toBe(['var' => 'FORGE_VERSION', 'value' => '1.21.1-52.1.21', 'version' => '52.1.21', 'experimental' => false])
        ->and($svc->loaderPin('neoforge', '26.1'))->toBe(['var' => 'NEOFORGE_VERSION', 'value' => '26.1.0.19-beta', 'version' => '26.1.0.19-beta', 'experimental' => true])
        ->and($svc->loaderPin('fabric', '1.21.8'))->toBeNull()
        ->and($svc->loaderPin('quilt', '1.21.8'))->toBeNull();
});

it('lets a Forge server through preflight with the build it will pin and a mods warning', function () {
    $pf = app(ServerVersionService::class)->preflight(mvsServer(), '1.21.1');

    expect($pf['can_proceed'])->toBeTrue(implode(' ', $pf['blockers']))
        ->and($pf['target']['loader'])->toBe('forge')
        ->and($pf['target']['loader_version'])->toBe('52.1.21')
        ->and(implode(' ', $pf['warnings']))->toContain('Mods for 1.20.1 usually stop the server');
});

it('warns about a beta build and blocks a version the loader has no release for', function () {
    $this->loader = 'neoforge';
    $pf = app(ServerVersionService::class)->preflight(mvsServer(), '26.1');
    expect($pf['can_proceed'])->toBeTrue()
        ->and(implode(' ', $pf['warnings']))->toContain('beta build');

    $pf = app(ServerVersionService::class)->preflight(mvsServer(), '1.19.2');
    expect($pf['can_proceed'])->toBeFalse()
        ->and(implode(' ', $pf['blockers']))->toContain('Neoforge has no release for Minecraft 1.19.2');
});

it('sets the Minecraft version and the Forge build, removes mod jars, then reinstalls', function () {
    $files = Mockery::mock(FileDeliveryService::class);
    $files->shouldReceive('deletePluginJars')->once()->with('srv-mvs', 'mods')->andReturn(['old.jar']);
    app()->instance(FileDeliveryService::class, $files);

    $r = app(VersionSwitchService::class)->apply(mvsServer(), '1.21.1', null, false, true);

    $vars = collect($this->ops->log)->firstWhere(0, 'set_vars')[2];
    expect($r['status'])->toBe('reinstalling')
        ->and($r['plugins_removed'])->toBe(['old.jar'])
        ->and($vars)->toBe(['MC_VERSION' => '1.21.1', 'MINECRAFT_VERSION' => '1.21.1', 'FORGE_VERSION' => '1.21.1-52.1.21'])
        ->and(collect($this->ops->log)->pluck(0)->all())->toContain('reinstall');
});

it('leaves the loader to the egg on Fabric', function () {
    $this->loader = 'fabric';
    $this->mc = '1.21.1';
    app(VersionSwitchService::class)->apply(mvsServer(), '1.21.8', null, false, false);

    expect(collect($this->ops->log)->firstWhere(0, 'set_vars')[2])->toBe(['MC_VERSION' => '1.21.8', 'MINECRAFT_VERSION' => '1.21.8']);
});
