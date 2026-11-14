<?php

use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Services\InstallPlanner;
use ServerSuite\Support\ChosenVersion;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\LoaderCompat;
use ServerSuite\Support\RepositoryManager;

/** #138: the version the user picked is installed, or refused with a reason; never swapped for the latest. */

function cvVersion(string $id, array $loaders, array $games): ModVersion
{
    return new ModVersion(versionId: $id, versionNumber: "n-{$id}", loaders: $loaders, gameVersions: $games,
        downloadUrl: "https://x.test/{$id}.jar", filename: "{$id}.jar", fileSize: 1, changelog: null);
}

beforeEach(function () {
    $all = [
        cvVersion('fab-new', ['fabric'], ['1.21.4']),
        cvVersion('neo', ['neoforge'], ['1.21.4']),
        cvVersion('fab-old', ['fabric'], ['1.21.1']),
    ];
    $this->driver = Mockery::mock(RepositoryContract::class);
    $this->driver->shouldReceive('versions')->andReturnUsing(function ($id, $loader = null, $game = null) use ($all) {
        return array_values(array_filter($all, fn ($v) => ($loader === null || in_array($loader, $v->loaders, true))
            && ($game === null || in_array($game, $v->gameVersions, true))));
    });
    $this->driver->shouldReceive('latestVersion')->andReturn($all[0]);
    $this->driver->shouldReceive('get')->andReturn(null);
    $this->driver->shouldReceive('dependencies')->andReturn([]);
});

it('keeps a pick from the compatible list', function () {
    expect(ChosenVersion::resolve($this->driver, 'SODIUM', 'fab-new', 'fabric', '1.21.4', 'Sodium')->versionId)->toBe('fab-new');
});

it('keeps a pick for this loader even when it is tagged for another game version', function () {
    expect(ChosenVersion::resolve($this->driver, 'SODIUM', 'fab-old', 'fabric', '1.21.4', 'Sodium')->versionId)->toBe('fab-old');
});

it('refuses a pick built for another loader instead of installing the latest', function () {
    expect(fn () => ChosenVersion::resolve($this->driver, 'SODIUM', 'neo', 'fabric', '1.21.4', 'Sodium'))
        ->toThrow(DeliveryException::class, 'Sodium n-neo is built for Neoforge, and this server runs Fabric');
});

it('says so when the picked version is gone', function () {
    expect(fn () => ChosenVersion::resolve($this->driver, 'SODIUM', 'deleted', 'fabric', '1.21.4', 'Sodium'))
        ->toThrow(DeliveryException::class, 'no longer available');
});

it('shows the reason in the install plan', function () {
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('driver')->andReturn($this->driver);
    app()->instance(RepositoryManager::class, $repos);
    \ServerSuite\Models\ModManagerSetting::forServer('srv-cv')->forceFill(['loader' => 'fabric', 'game_version' => '1.21.4'])->save();

    $planner = app(InstallPlanner::class);
    $plan = $planner->plan('modrinth', 'SODIUM', 'neo', (object) ['uuid' => 'srv-cv']);
    $item = $plan->items[0];

    expect($item->status)->toBe('manual')
        ->and($item->versionId)->toBe('neo')
        ->and($item->reason)->toContain('built for Neoforge');
});

it('knows which plugin and mod loaders a server runs', function () {
    expect(LoaderCompat::runs('purpur', ['paper']))->toBeTrue()
        ->and(LoaderCompat::runs('paper', ['bukkit', 'spigot']))->toBeTrue()
        ->and(LoaderCompat::runs('quilt', ['fabric']))->toBeTrue()
        ->and(LoaderCompat::runs('fabric', ['quilt']))->toBeFalse()
        ->and(LoaderCompat::runs('folia', ['paper']))->toBeFalse()
        ->and(LoaderCompat::runs('fabric', ['neoforge']))->toBeFalse()
        ->and(LoaderCompat::runs(null, ['neoforge']))->toBeTrue()
        ->and(LoaderCompat::runs('fabric', []))->toBeTrue();
});
