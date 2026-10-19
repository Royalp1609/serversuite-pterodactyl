<?php

use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\BackupRetentionResolver;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/** Bind the rotation service onto a fake panel and return the fake. */
function bindRotationFake(): FakePanelServerOps
{
    $fake = new FakePanelServerOps();

    app()->instance(
        BackupRotationService::class,
        new BackupRotationService($fake, new BackupRetentionResolver()),
    );

    return $fake;
}

it('rotates only servers that have opted in', function () {
    $fake = bindRotationFake();
    $fake->setLimit('opted-in', 10);
    $fake->seed('opted-in', [
        ['uuid' => 'c1', 'completed' => '2026-06-01T00:00:00+00:00'],
        ['uuid' => 'c2', 'completed' => '2026-06-02T00:00:00+00:00'],
    ]);

    // One server opted in, one not.
    $on = ModManagerSetting::forServer('opted-in');
    $on->backup_rotation_enabled = true;
    $on->save();
    ModManagerSetting::forServer('opted-out'); // exists but disabled

    $this->artisan('serversuite:rotate-backups')
        ->assertExitCode(0);

    // The opted-in server got a new backup; the opted-out one is untouched.
    expect($fake->listBackups('opted-in'))->toHaveCount(3);
    expect($fake->listBackups('opted-out'))->toHaveCount(0);
});

it('honours --server (bypassing opt-in) and --keep override', function () {
    $fake = bindRotationFake();
    $fake->setLimit('x', 10);
    $fake->seed('x', [
        ['uuid' => 'c1', 'completed' => '2026-06-01T00:00:00+00:00'],
        ['uuid' => 'c2', 'completed' => '2026-06-02T00:00:00+00:00'],
    ]);

    // No settings row at all — --server must still rotate it.
    $this->artisan('serversuite:rotate-backups', ['--server' => 'x', '--keep' => 2])
        ->assertExitCode(0);

    // keep=2 forces a trim down to one completed-successful backup (+ the new
    // in-progress one). Under the default keep (5) no trim would occur, so this
    // proves the override took effect.
    expect($fake->successfulCount('x'))->toBe(1);
});

it('reports cleanly when no servers are enabled', function () {
    bindRotationFake();

    $this->artisan('serversuite:rotate-backups')
        ->expectsOutputToContain('No servers are due for a scheduled backup.')
        ->assertExitCode(0);
});
