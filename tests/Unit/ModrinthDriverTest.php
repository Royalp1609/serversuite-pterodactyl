<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Drivers\ModrinthDriver;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

it('maps modrinth search hits to ModResult', function () {
    Http::fake([
        'api.modrinth.com/v2/search*' => Http::response([
            'hits' => [
                [
                    'project_id'          => 'AABBCCDD',
                    'slug'                => 'sodium',
                    'title'               => 'Sodium',
                    'description'         => 'Modern rendering engine',
                    'author'              => 'jellysquid3',
                    'downloads'           => 5000000,
                    'icon_url'            => 'https://cdn.modrinth.com/sodium.png',
                    'display_categories'  => ['fabric', 'optimization'],
                    'versions'            => ['1.21', '1.21.1'],
                ],
            ],
        ], 200),
    ]);

    $driver = new ModrinthDriver();
    $results = $driver->search('sodium', 'fabric', '1.21.1', 20);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBeInstanceOf(ModResult::class)
        ->and($results[0]->source)->toBe('modrinth')
        ->and($results[0]->id)->toBe('AABBCCDD')
        ->and($results[0]->name)->toBe('Sodium')
        ->and($results[0]->downloads)->toBe(5000000)
        ->and($results[0]->loaders)->toContain('fabric');
});

it('maps modrinth versions and picks the primary file', function () {
    Http::fake([
        'api.modrinth.com/v2/project/sodium/version*' => Http::response([
            [
                'id'             => 'VER123',
                'version_number' => 'mc1.21.1-0.6.0',
                'version_type'   => 'release',
                'loaders'        => ['fabric'],
                'game_versions'  => ['1.21.1'],
                'changelog'      => 'Bug fixes',
                'files'          => [
                    ['primary' => false, 'url' => 'https://cdn/secondary.jar', 'filename' => 'secondary.jar', 'size' => 100],
                    ['primary' => true,  'url' => 'https://cdn/sodium.jar',    'filename' => 'sodium.jar',    'size' => 999],
                ],
            ],
        ], 200),
    ]);

    $driver = new ModrinthDriver();
    $versions = $driver->versions('sodium', 'fabric', '1.21.1');

    expect($versions)->toHaveCount(1)
        ->and($versions[0])->toBeInstanceOf(ModVersion::class)
        ->and($versions[0]->filename)->toBe('sodium.jar')
        ->and($versions[0]->downloadUrl)->toBe('https://cdn/sodium.jar')
        ->and($versions[0]->fileSize)->toBe(999)
        ->and($versions[0]->channel)->toBe('release');
});

it('returns null for a 404 project', function () {
    Http::fake([
        'api.modrinth.com/v2/project/*' => Http::response([], 404),
    ]);

    $driver = new ModrinthDriver();
    expect($driver->get('does-not-exist'))->toBeNull();
});
