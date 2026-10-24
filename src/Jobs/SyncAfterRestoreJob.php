<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ServerSuite\Services\RestoreSyncService;

/**
 * A backup restore finished on a server (#60): bring the mod list back in
 * line. Queued with a short delay by the Server::updated hook, because the
 * panel writes the restore's success or failure to its activity log just
 * after it clears the server's status.
 */
class SyncAfterRestoreJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $serverUuid) {}

    public function handle(RestoreSyncService $sync): void
    {
        $model = \Pterodactyl\Models\Server::class;
        $server = class_exists($model) ? $model::query()->where('uuid', $this->serverUuid)->first() : null;
        if ($server !== null && $server->status === null) {
            $sync->completed($server);
            // Then start it again if it was running before the restore (#69).
            $auto = app(\ServerSuite\Services\AutoStartService::class);
            $sync->lastRestoreFailed ? $auto->forget($this->serverUuid) : $auto->resume($this->serverUuid, 'restore');
        }
    }
}
