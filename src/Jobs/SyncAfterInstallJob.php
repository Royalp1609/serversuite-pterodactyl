<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ServerSuite\Services\PostSwitchService;

/**
 * A server's install or reinstall finished: make sure its startup finds a
 * jar to run (PostSwitchService). Queued by the Server::updated hook.
 */
class SyncAfterInstallJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $serverUuid) {}

    public function handle(PostSwitchService $post): void
    {
        $post->finish($this->serverUuid);
    }
}
