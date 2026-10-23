<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use ServerSuite\Models\Modpack;
use ServerSuite\Services\ModpackInstaller;

/**
 * Advances one modpack install (#23). Each run does a bounded slice of work
 * (ModpackInstaller::step) and queues the next run, so a pack with hundreds
 * of files never hits the job timeout and progress shows as it goes. A lock
 * keeps two runs for the same pack from overlapping.
 */
class InstallModpackJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Long enough for Wings to unpack a large .mrpack (Pterodactyl waits up to 15 min). */
    public int $timeout = 1200;

    public function __construct(public int $modpackId) {}

    public function handle(ModpackInstaller $installer): void
    {
        try {
            $lock = Cache::lock("serversuite.modpack.{$this->modpackId}", $this->timeout + 60);
        } catch (\BadMethodCallException) {
            $lock = null;   // cache store without locks: runs are chained anyway
        }
        if ($lock !== null && ! $lock->get()) {
            return;   // another run holds it and will queue the next one
        }

        $next = null;
        try {
            $pack = Modpack::query()->find($this->modpackId);
            if ($pack === null || ! $pack->isRunning()) {
                return;
            }

            try {
                $next = $installer->step($pack);
            } catch (\Throwable $e) {
                report($e);
                $installer->fail($pack->refresh(), $e->getMessage());
            }
        } finally {
            $lock?->release();
        }

        if ($next !== null) {
            self::dispatch($this->modpackId)->delay($next);
        }
    }

    public function failed(\Throwable $e): void
    {
        $pack = Modpack::query()->find($this->modpackId);
        if ($pack?->isRunning()) {
            app(ModpackInstaller::class)->fail($pack, 'The install job stopped: ' . $e->getMessage());
        }
    }
}
