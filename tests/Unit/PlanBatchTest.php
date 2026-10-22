<?php

use ServerSuite\Services\InstallPlanner;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Support\Data\InstallPlan;
use ServerSuite\Support\Data\InstallPlanItem;

/** The install queue: several mods planned as one batch, each mod once. */

function pbItem(string $id, string $version, string $kind, string $status = 'install'): InstallPlanItem
{
    return new InstallPlanItem('modrinth', $id, "Mod {$id}", "{$id}-{$version}", $version, $status, $kind);
}

it('merges the plans so a shared dependency is installed once', function () {
    $planner = Mockery::mock(InstallPlanner::class);
    $planner->shouldReceive('plan')->with('modrinth', 'A', 'A-1', Mockery::any())
        ->andReturn(new InstallPlan([pbItem('A', '1', 'primary'), pbItem('LIB', '2', 'dependency')]));
    $planner->shouldReceive('plan')->with('modrinth', 'B', 'B-1', Mockery::any())
        ->andReturn(new InstallPlan([pbItem('B', '1', 'primary'), pbItem('LIB', '2', 'dependency')]));
    app()->instance(InstallPlanner::class, $planner);

    $plan = app(ModManagerService::class)->planBatch([
        ['source' => 'modrinth', 'mod_id' => 'A', 'version_id' => 'A-1'],
        ['source' => 'modrinth', 'mod_id' => 'B', 'version_id' => 'B-1'],
    ], (object) ['uuid' => 'srv-q']);

    expect(array_map(fn ($i) => $i->modId, $plan->toInstall()))->toBe(['A', 'B', 'LIB']);
});

it("keeps the version the user queued over the same mod pulled in as a dependency", function () {
    $planner = Mockery::mock(InstallPlanner::class);
    $planner->shouldReceive('plan')->with('modrinth', 'A', 'A-1', Mockery::any())
        ->andReturn(new InstallPlan([pbItem('A', '1', 'primary'), pbItem('LIB', '2', 'dependency')]));
    $planner->shouldReceive('plan')->with('modrinth', 'LIB', 'LIB-3', Mockery::any())
        ->andReturn(new InstallPlan([pbItem('LIB', '3', 'primary')]));
    app()->instance(InstallPlanner::class, $planner);

    $plan = app(ModManagerService::class)->planBatch([
        ['source' => 'modrinth', 'mod_id' => 'A', 'version_id' => 'A-1'],
        ['source' => 'modrinth', 'mod_id' => 'LIB', 'version_id' => 'LIB-3'],
    ], (object) ['uuid' => 'srv-q']);

    $lib = collect($plan->items)->firstWhere('modId', 'LIB');
    expect(count($plan->items))->toBe(2)
        ->and($lib->versionNumber)->toBe('3')
        ->and($lib->kind)->toBe('primary');
});
