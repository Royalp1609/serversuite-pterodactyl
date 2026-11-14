<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Services\ServerVersionService;

/** #122: a failed Paper build lookup isn't cached as "no builds". */

beforeEach(function () {
    Cache::flush();
    config(['serversuite.versions.paper_url' => 'https://fill.test/v3']);
});

it('does not cache a failure, and asks again next time', function () {
    $calls = 0;
    $healthy = false;
    Http::fake(function () use (&$calls, &$healthy) {
        $calls++;

        return $healthy
            ? Http::response([['id' => 7, 'channel' => 'STABLE', 'downloads' => ['server:default' => ['url' => 'https://x/7.jar']]]])
            : Http::response('down', 503);
    });

    $svc = app(ServerVersionService::class);
    expect($svc->paperBuilds('1.21.4'))->toBe([]);

    $healthy = true;
    expect(array_column($svc->paperBuilds('1.21.4'), 'build'))->toBe([7]);
    $before = $calls;
    expect(array_column($svc->paperBuilds('1.21.4'), 'build'))->toBe([7])
        ->and($calls)->toBe($before, 'a real answer is cached');
});

it('caches a 404 (Paper has no such version)', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(['message' => 'not found'], 404);
    });

    $svc = app(ServerVersionService::class);
    $svc->paperBuilds('1.99.9');
    $first = $calls;
    expect($svc->paperBuilds('1.99.9'))->toBe([])->and($calls)->toBe($first);
});

it('refuses a version string that is not a release version', function () {
    Http::fake();

    foreach (['../../x', '1.21.4/../../', '1.21.4?a=b', 'latest', ''] as $bad) {
        expect(app(ServerVersionService::class)->paperBuilds($bad))->toBe([]);
    }
    Http::assertNothingSent();
});
