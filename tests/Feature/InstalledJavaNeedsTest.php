<?php

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Models\ServerMod;
use ServerSuite\Services\JavaCompatService;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\JarJava;
use ServerSuite\Support\RepositoryManager;

/**
 * A version change reads the Java each installed plugin needs from its jar
 * (found through the repository by its installed version), so the new image
 * isn't older than the plugins (seen on the v2 test panel 2026-11-24: a switch
 * to 1.21.8 moved Java 25 -> 21 and VeinMiner Enchantment stopped loading).
 */
function ijnMod(string $id, string $version, ?string $disabledAt = null): ServerMod
{
    return ServerMod::query()->forceCreate([
        'server_uuid' => 'srv-j', 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => "Mod {$id}",
        'installed_version' => $version, 'target_filename' => "{$id}-{$version}.jar", 'install_path' => '/plugins', 'disabled_at' => $disabledAt,
    ]);
}

function ijnVersion(string $id, string $number): ModVersion
{
    return new ModVersion("{$id}-{$number}", $number, ['paper'], ['1.21.8'], "https://cdn.example.com/{$id}-{$number}.jar", "{$id}-{$number}.jar", 1000, null);
}

beforeEach(function () {
    Cache::flush();
    $repo = Mockery::mock(RepositoryContract::class);
    $repo->shouldReceive('versions')->andReturnUsing(fn (string $id) => [ijnVersion($id, '1.0'), ijnVersion($id, '2.0')]);
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('keys')->andReturn(['modrinth']);
    $repos->shouldReceive('driver')->with('modrinth')->andReturn($repo);
    app()->instance(RepositoryManager::class, $repos);

    $jars = Mockery::mock(JarJava::class);
    $jars->shouldReceive('required')->andReturnUsing(fn (string $url) => match (true) {
        str_contains($url, 'veinminer-2.0') => 25,
        str_contains($url, 'chunky') => 21,
        default => null,
    });
    $this->jars = $jars;
});

it('lists what the enabled installed plugins need, highest first', function () {
    ijnMod('chunky', '1.0');
    ijnMod('veinminer', '2.0');
    ijnMod('unknownjar', '1.0');
    ijnMod('veinminer-old', '1.0');
    ijnMod('off', '2.0', now()->toDateTimeString());

    $needs = (new JavaCompatService($this->jars))->installedNeeds((object) ['uuid' => 'srv-j']);

    expect($needs)->toBe([['mod' => 'Mod veinminer', 'java' => 25], ['mod' => 'Mod chunky', 'java' => 21]]);
});

it('remembers each mod version, so the next preflight asks nobody', function () {
    ijnMod('veinminer', '2.0');
    $svc = new JavaCompatService($this->jars);
    $svc->installedNeeds((object) ['uuid' => 'srv-j']);

    $this->jars->shouldNotReceive('required');
    app(RepositoryManager::class)->shouldNotReceive('driver');
    expect((new JavaCompatService($this->jars))->installedNeeds((object) ['uuid' => 'srv-j']))->toBe([['mod' => 'Mod veinminer', 'java' => 25]]);
});

it('can be turned off', function () {
    ijnMod('veinminer', '2.0');
    config(['serversuite.java_check' => false]);
    expect((new JavaCompatService($this->jars))->installedNeeds((object) ['uuid' => 'srv-j']))->toBe([]);
});
