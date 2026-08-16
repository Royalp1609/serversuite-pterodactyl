<?php

use ServerSuite\Services\ModManagerService;
use ServerSuite\Support\Data\ModResult;

/**
 * The ranking/dedupe logic is protected, so we exercise it through a tiny
 * reflection helper. This keeps the production API clean while still testing
 * the core merge behaviour directly.
 */
function rankAndDedupe(array $results, string $query, int $limit = 20): array
{
    $service = app(ModManagerService::class);
    $ref = new ReflectionMethod($service, 'rankAndDedupe');
    $ref->setAccessible(true);

    return $ref->invoke($service, collect($results), $query, $limit);
}

function makeMod(string $source, string $name, int $downloads): ModResult
{
    return new ModResult(
        source: $source,
        id: $source . '-' . $name,
        slug: strtolower($name),
        name: $name,
        description: '',
        author: '',
        downloads: $downloads,
        iconUrl: null,
        pageUrl: null,
        loaders: [],
        gameVersions: [],
        dependencies: [],
    );
}

it('collapses the same mod from two repositories keeping the more popular variant', function () {
    $results = [
        makeMod('modrinth', 'Sodium', 5_000_000),
        makeMod('curseforge', 'Sodium', 8_000_000),
    ];

    $ranked = rankAndDedupe($results, 'sodium');

    expect($ranked)->toHaveCount(1)
        ->and($ranked[0]->source)->toBe('curseforge')
        ->and($ranked[0]->downloads)->toBe(8_000_000);
});

it('ranks an exact name match above a more popular partial match', function () {
    $results = [
        makeMod('modrinth', 'Sodium Extra', 50_000_000),  // partial, hugely popular
        makeMod('modrinth', 'Sodium', 1_000),             // exact, tiny
    ];

    $ranked = rankAndDedupe($results, 'sodium');

    expect($ranked[0]->name)->toBe('Sodium');
});

it('respects the limit', function () {
    $results = [
        makeMod('modrinth', 'Alpha', 10),
        makeMod('modrinth', 'Beta', 20),
        makeMod('modrinth', 'Gamma', 30),
    ];

    $ranked = rankAndDedupe($results, 'x', 2);

    expect($ranked)->toHaveCount(2);
});
