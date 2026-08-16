<?php

use ServerSuite\Services\InstallPlanner;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerSetting;

/**
 * A fully in-memory repository driver. We seed it with mods + versions and it
 * answers the planner's get()/versions()/latestVersion() calls without HTTP.
 */
class FakePlannerDriver implements RepositoryContract
{
    /** @var array<string, ModResult> */
    public array $mods = [];
    /** @var array<string, ModVersion[]> */
    public array $versions = [];

    public function key(): string { return 'modrinth'; }
    public function label(): string { return 'Fake'; }
    public function isAvailable(): bool { return true; }

    public function search(string $query, ?string $loader = null, ?string $gameVersion = null, int $limit = 20): array
    {
        return array_values($this->mods);
    }

    public function get(string $modId): ?ModResult
    {
        return $this->mods[$modId] ?? null;
    }

    public function versions(string $modId, ?string $loader = null, ?string $gameVersion = null): array
    {
        return $this->versions[$modId] ?? [];
    }

    public function latestVersion(string $modId, ?string $loader = null, ?string $gameVersion = null): ?ModVersion
    {
        return $this->versions[$modId][0] ?? null;
    }

    // Test helpers ---------------------------------------------------------

    public function addMod(string $id, string $name): void
    {
        $this->mods[$id] = new ModResult('modrinth', $id, $id, $name, '', '', 0, null, null, [], [], []);
    }

    public function addVersion(string $modId, string $versionId, string $number, array $deps = [], string $url = 'https://cdn/x.jar'): void
    {
        $this->versions[$modId][] = new ModVersion(
            versionId: $versionId,
            versionNumber: $number,
            loaders: ['fabric'],
            gameVersions: ['1.21.1'],
            downloadUrl: $url,
            filename: "{$modId}.jar",
            fileSize: 100,
            changelog: null,
            hashes: [],
            channel: 'release',
            dependencies: $deps,
        );
    }
}

function planner(FakePlannerDriver $driver): InstallPlanner
{
    $manager = app(RepositoryManager::class);
    $manager->register('modrinth', $driver);

    return new InstallPlanner($manager, app(LoaderDetectionService::class));
}

function plannerServer(): object
{
    ModManagerSetting::create([
        'server_uuid' => 'srv-plan',
        'loader' => 'fabric',
        'game_version' => '1.21.1',
        'modrinth_enabled' => true,
        'curseforge_enabled' => false,
        'hangar_enabled' => false,
        'auto_update_checks' => true,
    ]);

    return (object) ['uuid' => 'srv-plan', 'egg' => (object) ['name' => 'Fabric']];
}

it('plans a simple install with no dependencies', function () {
    $d = new FakePlannerDriver();
    $d->addMod('sodium', 'Sodium');
    $d->addVersion('sodium', 'v1', '0.6.0');

    $plan = planner($d)->plan('modrinth', 'sodium', 'v1', plannerServer());

    expect($plan->toInstall())->toHaveCount(1)
        ->and($plan->needsConfirmation())->toBeFalse()
        ->and($plan->toInstall()[0]->status)->toBe('install');
});

it('resolves a required dependency into the plan', function () {
    $d = new FakePlannerDriver();
    $d->addMod('rei', 'Roughly Enough Items');
    $d->addMod('cloth', 'Cloth Config');
    $d->addVersion('rei', 'v1', '13.0', deps: ['cloth']);
    $d->addVersion('cloth', 'vc', '11.0');

    $plan = planner($d)->plan('modrinth', 'rei', 'v1', plannerServer());

    expect($plan->toInstall())->toHaveCount(2)
        ->and($plan->dependencyInstalls())->toHaveCount(1)
        ->and($plan->dependencyInstalls()[0]->modId)->toBe('cloth')
        ->and($plan->needsConfirmation())->toBeTrue();
});

it('marks an already-installed same version as satisfied', function () {
    $d = new FakePlannerDriver();
    $d->addMod('sodium', 'Sodium');
    $d->addVersion('sodium', 'v1', '0.6.0');

    $server = plannerServer();
    ServerMod::create([
        'server_uuid' => $server->uuid,
        'source' => 'modrinth',
        'source_id' => 'sodium',
        'mod_name' => 'Sodium',
        'installed_version' => '0.6.0',
        'target_filename' => 'sodium.jar',
    ]);

    $plan = planner($d)->plan('modrinth', 'sodium', 'v1', $server);

    expect($plan->satisfied())->toHaveCount(1)
        ->and($plan->toInstall())->toHaveCount(0);
});

it('flags a different installed version as a conflict', function () {
    $d = new FakePlannerDriver();
    $d->addMod('sodium', 'Sodium');
    $d->addVersion('sodium', 'v2', '0.6.5');

    $server = plannerServer();
    ServerMod::create([
        'server_uuid' => $server->uuid,
        'source' => 'modrinth',
        'source_id' => 'sodium',
        'mod_name' => 'Sodium',
        'installed_version' => '0.6.0',
        'target_filename' => 'sodium.jar',
    ]);

    $plan = planner($d)->plan('modrinth', 'sodium', 'v2', $server);

    expect($plan->conflicts())->toHaveCount(1)
        ->and($plan->conflicts()[0]->installedVersion)->toBe('0.6.0')
        ->and($plan->needsConfirmation())->toBeTrue();
});

it('marks a version with no download url as manual', function () {
    $d = new FakePlannerDriver();
    $d->addMod('blocked', 'Blocked Mod');
    $d->addVersion('blocked', 'vb', '1.0', url: '');

    $plan = planner($d)->plan('modrinth', 'blocked', 'vb', plannerServer());

    expect($plan->manual())->toHaveCount(1)
        ->and($plan->toInstall())->toHaveCount(0);
});

it('does not loop on circular dependencies', function () {
    $d = new FakePlannerDriver();
    $d->addMod('a', 'A');
    $d->addMod('b', 'B');
    $d->addVersion('a', 'va', '1.0', deps: ['b']);
    $d->addVersion('b', 'vb', '1.0', deps: ['a']);  // cycle back to A

    $plan = planner($d)->plan('modrinth', 'a', 'va', plannerServer());

    // Both resolved exactly once despite the cycle.
    expect($plan->items)->toHaveCount(2);
});
