<?php

use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\RepositoryManager;

/** #124: searching several repositories loses nothing between pages. */

function mrsMod(string $source, string $name, int $downloads = 1, ?string $id = null): ModResult
{
    return new ModResult(source: $source, id: $id ?? "{$source}-{$name}", slug: strtolower($name), name: $name, description: '', author: '',
        downloads: $downloads, iconUrl: null, pageUrl: null, loaders: ['paper'], gameVersions: ['1.21.4'], dependencies: []);
}

/** @param array<string, ModResult[]> $catalog source => results in that repository's own order */
function mrsBind(array $catalog): void
{
    $drivers = [];
    foreach ($catalog as $source => $all) {
        $d = Mockery::mock(RepositoryContract::class);
        $d->shouldReceive('key')->andReturn($source);
        $d->shouldReceive('search')->andReturnUsing(fn ($q, $l, $v, $limit, $sort, $offset) => array_slice($all, $offset, $limit));
        $drivers[] = $d;
    }
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('enabledDrivers')->andReturn($drivers);
    app()->instance(RepositoryManager::class, $repos);
    app()->forgetInstance(ModManagerService::class);
}

beforeEach(function () {
    ModManagerSetting::forServer('srv-mrs')->forceFill(['loader' => 'paper', 'game_version' => '1.21.4', 'modrinth_enabled' => true, 'hangar_enabled' => true])->save();
    $this->server = (object) ['uuid' => 'srv-mrs'];
});

it('shows every result from every repository across pages', function () {
    $modrinth = array_map(fn ($i) => mrsMod('modrinth', "World M{$i}"), range(1, 30));
    $hangar = array_map(fn ($i) => mrsMod('hangar', "World H{$i}"), range(1, 30));
    mrsBind(['modrinth' => $modrinth, 'hangar' => $hangar]);

    $seen = [];
    $offset = 0;
    do {
        $r = app(ModManagerService::class)->searchWithContext('world', $this->server, 20, null, null, null, null, $offset);
        foreach ($r['data'] as $m) {
            $seen[] = $m->id;
        }
        $offset = $r['next_offset'];
    } while ($r['has_more'] && $offset < 200);

    expect(count(array_unique($seen)))->toBe(60);
});

it('keeps two different mods that share a name in one repository', function () {
    mrsBind(['modrinth' => [mrsMod('modrinth', 'Backpacks', 10, 'A'), mrsMod('modrinth', 'Backpacks', 5, 'B')], 'hangar' => []]);

    $ids = array_map(fn ($m) => $m->id, app(ModManagerService::class)->search('backpacks', $this->server));

    expect($ids)->toContain('A')->toContain('B');
});

it('still collapses the same mod from two repositories', function () {
    mrsBind(['modrinth' => [mrsMod('modrinth', 'Chunky', 900)], 'hangar' => [mrsMod('hangar', 'Chunky', 100)]]);

    $r = app(ModManagerService::class)->search('chunky', $this->server);

    expect(array_map(fn ($m) => $m->source, $r))->toBe(['modrinth']);
});

it('merges a downloads sort across repositories instead of grouping by source', function () {
    mrsBind(['modrinth' => [mrsMod('modrinth', 'A', 900), mrsMod('modrinth', 'B', 100)], 'hangar' => [mrsMod('hangar', 'C', 500), mrsMod('hangar', 'D', 50)]]);

    $names = array_map(fn ($m) => $m->name, app(ModManagerService::class)->search('x', $this->server, 20, null, null, null, 'downloads'));

    expect($names)->toBe(['A', 'C', 'B', 'D']);
});
