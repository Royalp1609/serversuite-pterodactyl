<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\CrossplayService;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #28: Bedrock crossplay (Geyser + Floodgate) on Pterodactyl, as on Pelican. */

beforeEach(function () {
    ModManagerSetting::forServer('srv-x')->forceFill(['loader' => 'paper', 'game_version' => '26.1.2'])->save();
    $this->files = new FakeFiles();
    $this->app->instance(DaemonFileClient::class, $this->files);
    $build = fn (string $p, string $v, int $b, array $files) => ['project_id' => $p, 'version' => $v, 'build' => $b, 'changes' => [['summary' => 'update']],
        'downloads' => array_map(fn ($n) => ['name' => $n, 'sha256' => str_repeat('a', 64)], $files)];
    Http::fake(function (Request $r) use ($build) {
        $path = parse_url($r->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/v2/projects/geyser/versions/latest/builds/latest' => Http::response($build('geyser', '2.12.0', 1267, ['spigot' => 'Geyser-Spigot.jar', 'fabric' => 'Geyser-Fabric.jar'])),
            $path === '/v2/projects/floodgate/versions/latest/builds/latest' => Http::response($build('floodgate', '2.2.5', 141, ['spigot' => 'floodgate-spigot.jar'])),
            str_starts_with($path, '/v2/project/P1OZGk5p/version') => Http::response([[
                'id' => 'VIA1', 'version_number' => '5.12.2', 'loaders' => ['paper'], 'game_versions' => ['26.1.2'], 'dependencies' => [],
                'files' => [['url' => 'https://cdn.modrinth.com/data/P1OZGk5p/versions/VIA1/ViaVersion-5.12.2.jar', 'filename' => 'ViaVersion-5.12.2.jar', 'size' => 8, 'primary' => true, 'hashes' => []]],
            ]]),
            $path === '/v2/project/P1OZGk5p' => Http::response(['id' => 'P1OZGk5p', 'slug' => 'viaversion', 'title' => 'ViaVersion']),
            default => Http::response(['hits' => []]),
        };
    });
    $this->server = (object) ['uuid' => 'srv-x', 'allocation' => (object) ['ip' => '203.0.113.5', 'ip_alias' => null, 'port' => 25570],
        'allocations' => [(object) ['port' => 25570]]];
});

function fixtureGeyser(): string
{
    return (string) file_get_contents(__DIR__ . '/../Fixtures/geyser-config.yml');
}

it('has GeyserMC as an install source, not a search source', function () {
    $rm = app(RepositoryManager::class);
    expect($rm->keys())->not->toContain('geysermc');

    $v = $rm->driver('geysermc')->latestVersion('floodgate', 'purpur');
    expect([$v->filename, $v->versionNumber])->toBe(['floodgate-spigot.jar', '2.2.5-b141'])
        ->and($rm->driver('geysermc')->latestVersion('floodgate', 'fabric'))->toBeNull();
});

it('is offered on Paper, Purpur and Fabric, not on Folia or proxies', function () {
    foreach (['paper' => null, 'purpur' => null, 'fabric' => null, 'folia' => 'no Folia build', 'velocity' => 'proxy', 'bedrock' => 'needs Paper'] as $loader => $why) {
        ModManagerSetting::forServer('srv-x')->forceFill(['loader' => $loader])->save();
        $st = app(CrossplayService::class)->status($this->server);
        expect($st['supported'])->toBe($why === null);
        if ($why) {
            expect($st['reason'])->toContain($why);
        }
    }
});

it('plans Geyser, Floodgate and ViaVersion on Paper', function () {
    $items = app(CrossplayService::class)->planItems($this->server);

    expect(array_column($items, 'mod_id'))->toContain('geyser', 'floodgate', 'P1OZGk5p');
});

it('sets up NetherNet on the Java port once Geyser has written its config', function () {
    $svc = app(CrossplayService::class);
    expect(fn () => $svc->configure($this->server))->toThrow(RuntimeException::class);

    \ServerSuite\Models\ServerMod::query()->create(['server_uuid' => 'srv-x', 'source' => 'geysermc', 'source_id' => 'geyser', 'mod_name' => 'Geyser',
        'installed_version' => '2.12.0-b1267', 'target_filename' => 'Geyser-Spigot.jar', 'install_path' => '/plugins']);
    $this->files->put('/plugins/Geyser-Spigot/config.yml', fixtureGeyser());

    $set = $svc->configure($this->server);
    $yaml = $this->files->files['/plugins/Geyser-Spigot/config.yml'];

    expect($set['transport'])->toBe('nethernet')->and($set['port'])->toBe(25570)
        ->and(CrossplayService::bedrockPort($yaml))->toBe(25570)
        ->and(CrossplayService::bedrockValue($yaml, 'transport'))->toBe('nethernet')
        ->and(CrossplayService::signalingMode($yaml))->toBe('nxs')
        ->and($svc->status($this->server)['ready'])->toBeTrue();
});

it('changes only the bedrock port line', function () {
    $a = explode("\n", fixtureGeyser());
    $b = explode("\n", (string) CrossplayService::withBedrockPort(fixtureGeyser(), 25570));
    $changed = array_keys(array_diff_assoc($a, $b));

    expect($b)->toHaveCount(count($a))
        ->and(array_map(fn ($i) => $a[$i], $changed))->toBe(['  port: 19132'])
        ->and(CrossplayService::withBedrockPort("java:\n  port: 1\n", 2))->toBeNull();
});
