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

it('waits for the chosen hour when a schedule hour is set (#4)', function () {
    $fake = bindRotationFake();
    $fake->setLimit('anchored', 10);

    $s = ModManagerSetting::forServer('anchored');
    $s->backup_rotation_enabled = true;
    $s->backup_interval_hours = 24;
    $s->backup_schedule_hour = 4;
    $s->backup_last_run_at = \Carbon\Carbon::parse('2026-10-19 04:00');
    $s->save();

    \Carbon\Carbon::setTestNow('2026-10-20 03:45');
    $this->artisan('serversuite:rotate-backups')->assertExitCode(0);
    expect($fake->listBackups('anchored'))->toHaveCount(0);

    \Carbon\Carbon::setTestNow('2026-10-20 04:00');
    $this->artisan('serversuite:rotate-backups')->assertExitCode(0);
    expect($fake->listBackups('anchored'))->toHaveCount(1);
    expect($s->refresh()->backup_last_run_at->format('Y-m-d H:i'))->toBe('2026-10-20 04:00');

    \Carbon\Carbon::setTestNow('2026-10-20 04:15');
    $this->artisan('serversuite:rotate-backups')->assertExitCode(0);
    expect($fake->listBackups('anchored'))->toHaveCount(1);

    \Carbon\Carbon::setTestNow();
});

it('stores the true last run when the backup timezone differs from the app timezone', function () {
    // Eloquent writes a Carbon's wall-clock time without converting it, so
    // saving the backup-timezone "now" stored London time as UTC: read back an
    // hour late in BST, which made a weekly schedule skip a week.
    config(['app.timezone' => 'UTC', 'serversuite.backups.timezone' => 'Europe/London']);
    $fake = bindRotationFake();
    $fake->setLimit('weekly', 10);

    $s = ModManagerSetting::forServer('weekly');
    $s->backup_rotation_enabled = true;
    $s->backup_interval_hours = 168;
    $s->backup_schedule_hour = 3;
    $s->save();

    // 03:00 London on Tue 7 Jul 2026 is 02:00 UTC.
    \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse('2026-07-07 02:00', 'UTC'));
    $this->artisan('serversuite:rotate-backups')->assertExitCode(0);
    expect($s->refresh()->backup_last_run_at->equalTo(\Carbon\CarbonImmutable::parse('2026-07-07 02:00', 'UTC')))->toBeTrue();

    // A week later, same hour: due again, not pushed to the week after.
    \Carbon\Carbon::setTestNow(\Carbon\CarbonImmutable::parse('2026-07-14 02:00', 'UTC'));
    $this->artisan('serversuite:rotate-backups')->assertExitCode(0);
    expect($s->refresh()->backup_last_run_at->equalTo(\Carbon\CarbonImmutable::parse('2026-07-14 02:00', 'UTC')))->toBeTrue();

    \Carbon\Carbon::setTestNow();
});
