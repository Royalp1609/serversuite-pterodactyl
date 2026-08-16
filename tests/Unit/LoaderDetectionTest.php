<?php

use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Support\Data\LoaderDetectionResult;

/**
 * Build a fake server object with an egg, mimicking the Pterodactyl model
 * shape the detector reads (egg->uuid, egg->name, egg->docker_images,
 * egg->startup, egg->script_install, server->startup, server->image).
 *
 * $variables is a list of [env_variable, server_value] pairs.
 */
function fakeServer(array $egg = [], array $server = [], array $variables = []): object
{
    $eggObj = (object) array_merge([
        'uuid'           => null,
        'name'           => null,
        'docker_images'  => null,
        'startup'        => null,
        'script_install' => null,
    ], $egg);

    $vars = collect($variables)->map(fn ($pair) => (object) [
        'env_variable' => $pair[0],
        'server_value' => $pair[1],
    ]);

    return (object) array_merge([
        'uuid'      => 'test-uuid',
        'egg'       => $eggObj,
        'startup'   => null,
        'image'     => null,
        'variables' => $vars,
    ], $server);
}

beforeEach(function () {
    // Register the real Forge egg uuid we verified on the live panel.
    config()->set('serversuite.detection.egg_uuids', [
        '9588665d-1301-4efd-9cfc-b2e2405d31d7' => 'forge',
    ]);
});

it('detects by mapped egg uuid with full confidence', function () {
    $server = fakeServer(['uuid' => '9588665d-1301-4efd-9cfc-b2e2405d31d7', 'name' => 'Anything']);

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->loader)->toBe('forge')
        ->and($result->confidence)->toBe(100)
        ->and($result->signal)->toBe('egg_uuid')
        ->and($result->isConfident())->toBeTrue();
});

it('detects forge from the egg name', function () {
    $server = fakeServer(['name' => 'Forge Minecraft']);

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->loader)->toBe('forge')
        ->and($result->signal)->toBe('egg_name');
});

it('does not mistake neoforge for forge', function () {
    $server = fakeServer(['name' => 'NeoForge Server']);

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->loader)->toBe('neoforge');
});

it('detects fabric from the install script when name is generic', function () {
    $server = fakeServer([
        'name'           => 'Custom Modpack',
        'script_install' => "#!/bin/bash\n# Fabric installer\ncurl fabric...",
    ]);

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->loader)->toBe('fabric')
        ->and($result->signal)->toBe('install_script')
        ->and($result->confidence)->toBeLessThan(60)
        ->and($result->isConfident())->toBeFalse();
});

it('returns unknown when no signal matches', function () {
    $server = fakeServer(['name' => 'My Cool Server']);

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->loader)->toBeNull()
        ->and($result->isResolved())->toBeFalse()
        ->and($result->signal)->toBe('none');
});

it('reads a concrete minecraft version from MC_VERSION', function () {
    $server = fakeServer(
        ['name' => 'Forge Minecraft'],
        [],
        [['MC_VERSION', '1.21.1'], ['BUILD_TYPE', 'recommended']]
    );

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->gameVersion)->toBe('1.21.1');
});

it('treats MC_VERSION=latest as unknown (null), never a guess', function () {
    $server = fakeServer(
        ['name' => 'Forge Minecraft'],
        [],
        [['MC_VERSION', 'latest']]
    );

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->gameVersion)->toBeNull();
});

it('never scrapes a version out of the forge version string', function () {
    // Regression test for the "1.2" bug: forge-26.1.2-64.0.8 must NOT yield "1.2".
    $server = fakeServer(
        ['name' => 'Forge Minecraft'],
        ['startup' => 'java -jar forge-26.1.2-64.0.8-shim.jar'],
        [['SERVER_JARFILE', 'forge-26.1.2-64.0.8-shim.jar'], ['MC_VERSION', 'latest']]
    );

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->gameVersion)->toBeNull();
});

it('ignores an empty version variable', function () {
    $server = fakeServer(
        ['name' => 'Forge Minecraft'],
        [],
        [['MC_VERSION', '']]
    );

    $result = app(LoaderDetectionService::class)->detectDetailed($server);

    expect($result->gameVersion)->toBeNull();
});

it('never silently defaults an unknown loader when called via detect()', function () {
    $server = fakeServer(['name' => 'Totally Ambiguous Server', 'uuid' => 'x']);
    $server->uuid = 'detect-test-uuid';

    $loader = app(LoaderDetectionService::class)->detect($server);

    // The old behaviour returned 'fabric' here. The hardened version returns null.
    expect($loader)->toBeNull();
});
