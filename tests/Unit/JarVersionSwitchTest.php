<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\Data\LoaderDetectionResult;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/** Versions tab on Purpur, Folia, Spigot, Sponge and Vanilla: lists from MCJars, the egg's own version variable written. */

function jvsServer(): object
{
    return (object) ['uuid' => 'srv-jvs', 'skip_scripts' => false, 'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'egg' => (object) ['docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]];
}

beforeEach(function () {
    config(['serversuite.versions.mcjars' => true]);
    $this->ops = new FakePanelServerOps();
    $this->ops->states['srv-jvs'] = ['power' => 'offline', 'install' => null];
    app()->instance(PanelServerOps::class, $this->ops);
    app()->instance(DaemonFileClient::class, Mockery::mock(DaemonFileClient::class)->shouldIgnoreMissing());

    $this->loader = 'purpur';
    $detect = Mockery::mock(LoaderDetectionService::class)->shouldIgnoreMissing();
    foreach (['detectDetailed', 'eggLoader'] as $m) {
        $detect->shouldReceive($m)->andReturnUsing(fn () => new LoaderDetectionResult($this->loader, 90, 'egg', '1.21.1'));
    }
    $detect->shouldReceive('detect')->andReturnUsing(fn () => $this->loader);
    app()->instance(LoaderDetectionService::class, $detect);

    $list = ['builds' => ['1.21.4' => ['type' => 'RELEASE'], '1.21.1' => ['type' => 'RELEASE'], '25w14a' => ['type' => 'SNAPSHOT']]];
    Http::fake([
        'mcjars.app/api/v2/builds/SPONGE/1.21.4' => Http::response(['builds' => [
            ['projectVersionId' => '1.21.4-14.0.1-RC2', 'experimental' => true],
            ['projectVersionId' => '1.21.4-14.0.0', 'experimental' => false],
        ]]),
        'mcjars.app/api/v2/builds/SPONGE/1.21.1' => Http::response(['builds' => []]),
        'mcjars.app/api/v2/builds/SPIGOT/1.21.4' => Http::response(['builds' => [['jarUrl' => 'https://files.mcjars.app/spigot/1.21.4/1/server.jar']]]),
        'mcjars.app/api/v2/builds/PURPUR' => Http::response($list),
        'mcjars.app/api/v2/builds/FOLIA' => Http::response($list),
        'mcjars.app/api/v2/builds/SPIGOT' => Http::response($list),
        'mcjars.app/api/v2/builds/SPONGE' => Http::response($list),
        'mcjars.app/api/v2/builds/VANILLA' => Http::response($list),
        '*' => Http::response([], 404),
    ]);
});

it('lists release versions for every single-jar loader', function (string $loader) {
    expect(app(ServerVersionService::class)->available($loader))
        ->toBe(['supported' => true, 'loader' => $loader, 'versions' => ['1.21.4', '1.21.1']]);
})->with(['purpur', 'folia', 'spigot', 'sponge', 'vanilla']);

it('lets a Purpur server through preflight and keeps its plugins by default', function () {
    $pf = app(ServerVersionService::class)->preflight(jvsServer(), '1.21.4');

    expect($pf['can_proceed'])->toBeTrue(implode(' ', $pf['blockers']))
        ->and($pf['target']['loader'])->toBe('purpur')
        ->and(implode(' ', $pf['warnings']))->not->toContain('Mods are built');
});

it('writes the version every stock egg reads, and BUILD_NUMBER latest on Purpur', function () {
    $r = app(VersionSwitchService::class)->apply(jvsServer(), '1.21.4', null, false, false);

    expect($r['status'])->toBe('reinstalling')
        ->and(collect($this->ops->log)->firstWhere(0, 'set_vars')[2])->toBe([
            'MINECRAFT_VERSION' => '1.21.4', 'MC_VERSION' => '1.21.4', 'VANILLA_VERSION' => '1.21.4', 'DL_VERSION' => '1.21.4',
            'BUILD_NUMBER' => 'latest',
        ]);
});

it('pins a direct jar on Spigot so the egg skips BuildTools', function () {
    $this->loader = 'spigot';
    app(VersionSwitchService::class)->apply(jvsServer(), '1.21.4', null, false, false);

    expect(collect($this->ops->log)->firstWhere(0, 'set_vars')[2]['DL_PATH'])->toBe('https://files.mcjars.app/spigot/1.21.4/1/server.jar');
});

it('pins the newest stable Sponge build and blocks a version Sponge has no build for', function () {
    $this->loader = 'sponge';
    $pf = app(ServerVersionService::class)->preflight(jvsServer(), '1.21.4');
    expect($pf['can_proceed'])->toBeTrue()
        ->and($pf['target']['loader_version'])->toBe('1.21.4-14.0.0');

    app(VersionSwitchService::class)->apply(jvsServer(), '1.21.4', null, false, false);
    expect(collect($this->ops->log)->firstWhere(0, 'set_vars')[2]['SPONGE_VERSION'])->toBe('1.21.4-14.0.0');

    $pf = app(ServerVersionService::class)->preflight(jvsServer(), '1.21.1');
    expect($pf['can_proceed'])->toBeFalse()
        ->and(implode(' ', $pf['blockers']))->toContain('Could not find a Sponge build');
});

it('removes plugin jars, not mods, when asked on a single-jar loader', function () {
    $files = Mockery::mock(FileDeliveryService::class);
    $files->shouldReceive('deletePluginJars')->once()->with('srv-jvs', 'plugins')->andReturn([]);
    app()->instance(FileDeliveryService::class, $files);

    app(VersionSwitchService::class)->apply(jvsServer(), '1.21.4', null, false, true);
});

it('reads the current version from the Vanilla, Spigot and Sponge egg variables', function (string $var, string $value, string $want) {
    $svc = new class (...array_map(fn ($p) => Mockery::mock($p->getType()->getName())->shouldIgnoreMissing(), (new ReflectionClass(LoaderDetectionService::class))->getConstructor()?->getParameters() ?? [])) extends LoaderDetectionService {
        public function gv(object $s): ?string { return $this->detectGameVersion($s, (object) []); }
    };
    $server = (object) ['uuid' => 'x', 'variables' => [(object) ['env_variable' => $var, 'server_value' => $value]]];

    expect($svc->gv($server))->toBe($want);
})->with([
    ['VANILLA_VERSION', '1.21.4', '1.21.4'],
    ['DL_VERSION', '1.21.8', '1.21.8'],
    ['SPONGE_VERSION', '1.21.1-12.0.4', '1.21.1'],
]);
