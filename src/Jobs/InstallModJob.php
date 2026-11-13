<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

    /**
     * A run can take longer than the queue's retry_after (90 s on the panels),
     * so a copy of the job gets picked up while the first is still pulling.
     * A lock per task keeps the copy from delivering the mod a second time:
     * it waits (release) until the first run is done, then sees the task has
     * finished and stops (#118). Exceptions are caught in handle(), so the
     * extra tries are only used by those waits and by a run that died.
     */
    public int $tries = 10;

    /** Longer than the Wings pull timeout (serversuite.delivery.pull_timeout, 600 s). */
    public int $timeout = 660;

    public function __construct(public int $taskId) {}

    public function handle(ModManagerService $mods): void
    {
        $task = ModInstallTask::find($this->taskId);

        if (! $task || $this->finished($task)) {
            return;
        }

        try {
            $lock = Cache::lock($this->lockKey(), $this->timeout + 60);
        } catch (\BadMethodCallException) {
            $lock = null;   // cache store without locks
        }
        if ($lock !== null && ! $lock->get()) {
            $this->release(30);   // the first run is still going

            return;
        }

        try {
            if ($this->finished($task->refresh())) {
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
        } finally {
            $lock?->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        $task = ModInstallTask::find($this->taskId);
        if (! $task || $this->finished($task)) {
            return;
        }
        try {
            $lock = Cache::lock($this->lockKey(), 60);
            if (! $lock->get()) {
                return;   // a run is still working on it and will set the status
            }
            $lock->release();
        } catch (\BadMethodCallException) {
            // no locks: fall through
        }
        $task->markFailed('Job failed: ' . $e->getMessage());
    }

    private function finished(ModInstallTask $task): bool
    {
        return in_array($task->status, [ModInstallTask::STATUS_SUCCESS, ModInstallTask::STATUS_FAILED, ModInstallTask::STATUS_SKIPPED], true);
    }

    private function lockKey(): string
    {
        return "serversuite.mod-install-task.{$this->taskId}";
    }
}
