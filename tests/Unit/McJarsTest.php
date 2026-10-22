<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Services\McJarsService;

/** #22: direct jars from MCJars for the egg's DL_PATH on a switch. */

beforeEach(fn () => config(['serversuite.versions.mcjars' => true]));

it('finds the newest build with a direct jar URL', function () {
    Http::fake(['mcjars.app/api/v2/builds/PAPER/26.1.2' => Http::response(['builds' => [
        ['buildNumber' => 12, 'jarUrl' => null],
        ['buildNumber' => 11, 'jarUrl' => 'https://fill-data.papermc.io/v1/objects/abc/paper-26.1.2-11.jar'],
    ]])]);

    expect(app(McJarsService::class)->pinFor('paper', '26.1.2'))
        ->toBe(['url' => 'https://fill-data.papermc.io/v1/objects/abc/paper-26.1.2-11.jar', 'file' => 'server.jar']);
});

it('pins nothing for installer loaders, bad versions, misses or when turned off', function () {
    Http::fake(['*' => Http::response([], 404)]);
    $svc = app(McJarsService::class);

    expect($svc->pinFor('forge', '1.20.1'))->toBeNull()
        ->and($svc->pinFor('neoforge', '21.1'))->toBeNull()
        ->and($svc->pinFor('paper', '1.21; rm'))->toBeNull()
        ->and($svc->pinFor('paper', '9.9.9'))->toBeNull()
        ->and($svc->pinFor(null, '1.21'))->toBeNull();

    config(['serversuite.versions.mcjars' => false]);
    Http::fake(['*' => Http::response(['builds' => [['jarUrl' => 'https://x/y.jar']]])]);
    expect($svc->pinFor('purpur', '1.21.4'))->toBeNull();
});

it('reads the loader from an egg name', function () {
    expect(McJarsService::keywordForEgg('Purpur (1.21)'))->toBe('purpur')
        ->and(McJarsService::keywordForEgg('NeoForge'))->toBe('neoforge')
        ->and(McJarsService::keywordForEgg('Vanilla Minecraft'))->toBeNull();
});
