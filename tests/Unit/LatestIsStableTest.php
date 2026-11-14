<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Drivers\CurseForgeDriver;
use ServerSuite\Drivers\ModrinthDriver;

/** #126: "latest" is the newest release build; pre-releases only when there is no release. */

function lisModrinth(string $id, string $type): array
{
    return ['id' => $id, 'version_number' => $id, 'version_type' => $type, 'loaders' => ['fabric'], 'game_versions' => ['1.21.1'],
        'dependencies' => [], 'files' => [['url' => "https://cdn.modrinth.com/{$id}.jar", 'filename' => "{$id}.jar", 'size' => 1, 'primary' => true, 'hashes' => []]]];
}

beforeEach(fn () => Cache::flush());

it('skips newer betas and alphas for the newest release on Modrinth', function () {
    Http::fake(['api.modrinth.com/v2/project/sodium/version*' => Http::response([
        lisModrinth('0.7.0-beta.4', 'beta'), lisModrinth('0.7.0-alpha.1', 'alpha'), lisModrinth('0.6.13', 'release'), lisModrinth('0.6.12', 'release'),
    ])]);

    expect((new ModrinthDriver())->latestVersion('sodium', 'fabric', '1.21.1')->versionNumber)->toBe('0.6.13');
});

it('falls back to the newest beta, then alpha, when a project has no release', function () {
    Http::fake([
        'api.modrinth.com/v2/project/c2me/version*' => Http::response([lisModrinth('0.4.0-alpha.2', 'alpha'), lisModrinth('0.3.9-beta.1', 'beta')]),
        'api.modrinth.com/v2/project/alphaonly/version*' => Http::response([lisModrinth('a2', 'alpha'), lisModrinth('a1', 'alpha')]),
    ]);

    expect((new ModrinthDriver())->latestVersion('c2me', 'fabric', '1.21.1')->versionNumber)->toBe('0.3.9-beta.1')
        ->and((new ModrinthDriver())->latestVersion('alphaonly', 'fabric', '1.21.1')->versionNumber)->toBe('a2');
});

it('skips beta files for the newest release file on CurseForge', function () {
    config()->set('serversuite.curseforge_api_key', 'test-key');
    $file = fn (int $id, int $type) => ['id' => $id, 'displayName' => "JEI {$id}", 'fileName' => "jei-{$id}.jar", 'releaseType' => $type,
        'fileLength' => 1, 'downloadUrl' => "https://cf/{$id}.jar", 'gameVersions' => ['1.21.1', 'Forge'], 'dependencies' => []];
    Http::fake(['api.curseforge.com/v1/mods/*/files*' => Http::response(['data' => [$file(3, 2), $file(2, 3), $file(1, 1)]])]);

    expect((new CurseForgeDriver())->latestVersion('238222', 'forge', '1.21.1')->versionId)->toBe('1');
});
