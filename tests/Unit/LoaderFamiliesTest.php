<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Drivers\HangarDriver;
use ServerSuite\Drivers\ModrinthDriver;

/** #125: Purpur (and other Paper forks) find plugins tagged paper/spigot/bukkit. */

beforeEach(fn () => Cache::flush());

it('searches Modrinth for every loader a Purpur server runs', function () {
    Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0])]);

    (new ModrinthDriver())->search('chunky', 'purpur', '1.21.4');

    Http::assertSent(function ($r) {
        $facets = json_decode($r->data()['facets'] ?? '[]', true);

        return in_array(['categories:purpur', 'categories:paper', 'categories:spigot', 'categories:bukkit'], $facets, true);
    });
});

it('asks Modrinth for versions of every loader the server runs', function () {
    Http::fake(['api.modrinth.com/v2/project/chunky/version*' => Http::response([])]);

    (new ModrinthDriver())->versions('chunky', 'purpur', '1.21.4');
    (new ModrinthDriver())->versions('chunky', 'fabric', '1.21.4');

    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'loaders=["purpur","paper","spigot","bukkit"]'));
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'loaders=["fabric"]'));
});

it('maps Purpur to Hangar\'s Paper platform', function () {
    Http::fake(['hangar.papermc.io/*' => Http::response(['result' => [], 'pagination' => ['count' => 0]])]);

    (new HangarDriver())->search('chunky', 'purpur', '1.21.4');

    Http::assertSent(fn ($r) => ($r->data()['platform'] ?? null) === 'PAPER');
});
