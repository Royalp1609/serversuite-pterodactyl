<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use ServerSuite\Jobs\InstallModJob;
use ServerSuite\Jobs\InstallModpackJob;
use ServerSuite\Models\ModInstallTask;
use ServerSuite\Models\Modpack;
use ServerSuite\Services\ModManagerService;

/**
 * #118: a job run can outlast the queue's retry_after (90 s), so a copy is
 * picked up while the first run is still going. The copy must not deliver
 * the mod again or fail the install.
 */
function qrTask(string $status = ModInstallTask::STATUS_PENDING): ModInstallTask
{
    return ModInstallTask::query()->create([
        'batch_id' => (string) Str::uuid(), 'server_uuid' => 'srv-q', 'source' => 'modrinth', 'source_id' => 'AAA',
        'mod_name' => 'A', 'version_id' => 'v1', 'kind' => 'primary', 'status' => $status,
    ]);
}

it('a copy of the mod job waits while the first run holds the lock', function () {
    $task = qrTask(ModInstallTask::STATUS_RUNNING);
    $held = Cache::lock("serversuite.mod-install-task.{$task->id}", 600);
    expect($held->get())->toBeTrue();

    $mods = Mockery::mock(ModManagerService::class);
    $mods->shouldNotReceive('executeTask');
    $job = (new InstallModJob($task->id))->withFakeQueueInteractions();
    $job->handle($mods);

    $job->assertReleased(30);
    expect($task->refresh()->status)->toBe(ModInstallTask::STATUS_RUNNING);
    $held->release();
});

it('a copy of the mod job stops once the task has finished', function () {
    $task = qrTask(ModInstallTask::STATUS_SUCCESS);
    $mods = Mockery::mock(ModManagerService::class);
    $mods->shouldNotReceive('executeTask');

    $job = (new InstallModJob($task->id))->withFakeQueueInteractions();
    $job->handle($mods);

    $job->assertNotReleased();
});

it('runs the task once and frees the lock', function () {
    $task = qrTask();
    $mods = Mockery::mock(ModManagerService::class);
    $mods->shouldReceive('executeTask')->once();

    (new InstallModJob($task->id))->withFakeQueueInteractions()->handle($mods);

    expect($task->refresh()->status)->toBe(ModInstallTask::STATUS_SUCCESS)
        ->and(Cache::lock("serversuite.mod-install-task.{$task->id}", 1)->get())->toBeTrue();
});

it('failed() leaves a task alone while a run still holds its lock', function () {
    $task = qrTask(ModInstallTask::STATUS_RUNNING);
    $held = Cache::lock("serversuite.mod-install-task.{$task->id}", 600);
    $held->get();

    (new InstallModJob($task->id))->failed(new RuntimeException('max attempts'));

    expect($task->refresh()->status)->toBe(ModInstallTask::STATUS_RUNNING);
    $held->release();

    (new InstallModJob($task->id))->failed(new RuntimeException('worker died'));
    expect($task->refresh()->status)->toBe(ModInstallTask::STATUS_FAILED);
});

it('failed() leaves a modpack install alone while a run holds its lock', function () {
    $pack = Modpack::query()->create(['server_uuid' => 'srv-q', 'status' => Modpack::STATUS_INSTALLING, 'phase' => 'files', 'state' => []]);
    $held = Cache::lock("serversuite.modpack.{$pack->id}", 600);
    $held->get();

    (new InstallModpackJob($pack->id))->failed(new RuntimeException('max attempts'));

    expect($pack->refresh()->status)->toBe(Modpack::STATUS_INSTALLING);
    $held->release();

    (new InstallModpackJob($pack->id))->failed(new RuntimeException('worker died'));
    expect($pack->refresh()->status)->toBe(Modpack::STATUS_FAILED);
});
