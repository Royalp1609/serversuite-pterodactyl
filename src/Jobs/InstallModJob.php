<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use ServerSuite\Models\ModInstallTask;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Exceptions\DeliveryException;

/**
 * Installs a single mod described by a ModInstallTask. Dispatched (one per
 * mod) when an install plan is executed asynchronously — i.e. when it has
 * dependencies or is part of a bulk action. The UI polls the task batch for
 * progress.
 *
 * The job resolves the server fresh by UUID (we never serialize panel models),
 * updates task status, and delegates the actual work to ModManagerService.
 */
class InstallModJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 180;

    public function __construct(public int $taskId) {}

    public function handle(ModManagerService $mods): void
    {
        $task = ModInstallTask::find($this->taskId);

        if (! $task || $task->status === ModInstallTask::STATUS_SUCCESS) {
            return;
        }

        $task->markRunning();

        try {
            $mods->executeTask($task);
            $task->markSuccess();
        } catch (DeliveryException $e) {
            $task->markFailed($e->getMessage());
        } catch (\Throwable $e) {
            $task->markFailed('Unexpected error: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        $task = ModInstallTask::find($this->taskId);
        $task?->markFailed('Job failed: ' . $e->getMessage());
    }
}
