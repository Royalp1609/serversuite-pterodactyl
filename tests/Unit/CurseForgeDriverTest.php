<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Drivers\CurseForgeDriver;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

it('maps curseforge search data to ModResult', function () {
    config()->set('serversuite.curseforge_api_key', 'test-key');

    Http::fake([
        'api.curseforge.com/v1/mods/search*' => Http::response([
            'data' => [
                [
                    'id'            => 238222,
                    'slug'          => 'jei',
                    'name'          => 'Just Enough Items',
                    'summary'       => 'Item and recipe viewing mod',
                    'downloadCount' => 300000000,
                    'authors'       => [['id' => 1, 'name' => 'mezz']],
                    'logo'          => ['url' => 'https://cf/jei.png'],
                    'links'         => ['websiteUrl' => 'https://curseforge.com/jei'],
                    'latestFilesIndexes' => [
                        ['gameVersion' => '1.21.1', 'modLoader' => 1],
                        ['gameVersion' => '1.21.1', 'modLoader' => 4],
                    ],
                ],
            ],
        ], 200),
    ]);

    $driver = new CurseForgeDriver();
    $results = $driver->search('jei', null, '1.21.1', 20);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toBeInstanceOf(ModResult::class)
        ->and($results[0]->id)->toBe('238222')
        ->and($results[0]->author)->toBe('mezz')
        ->and($results[0]->loaders)->toContain('forge')
        ->and($results[0]->loaders)->toContain('fabric')
        ->and($results[0]->gameVersions)->toContain('1.21.1');
});

it('splits loader names from game versions in a file', function () {
    config()->set('serversuite.curseforge_api_key', 'test-key');

    Http::fake([
        'api.curseforge.com/v1/mods/*/files*' => Http::response([
            'data' => [
                [
                    'id'           => 5000,
                    'displayName'  => 'JEI 19.0',
                    'fileName'     => 'jei-1.21.1.jar',
                    'releaseType'  => 1,
                    'fileLength'   => 12345,
                    'downloadUrl'  => 'https://cf/jei.jar',
                    'gameVersions' => ['1.21.1', 'Forge'],
                    'dependencies' => [
                        ['modId' => 999, 'relationType' => 3],
                        ['modId' => 111, 'relationType' => 2],
                    ],
                ],
            ],
        ], 200),
    ]);

    $driver = new CurseForgeDriver();
    $versions = $driver->versions('238222', 'forge', '1.21.1');

    expect($versions)->toHaveCount(1)
        ->and($versions[0])->toBeInstanceOf(ModVersion::class)
        ->and($versions[0]->loaders)->toBe(['forge'])
        ->and($versions[0]->gameVersions)->toBe(['1.21.1'])
        ->and($versions[0]->downloadUrl)->toBe('https://cf/jei.jar');
});

it('is unavailable without an api key', function () {
    config()->set('serversuite.curseforge_api_key', '');

    $driver = new CurseForgeDriver();
    expect($driver->isAvailable())->toBeFalse();
});
