<?php

namespace ServerSuite\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ServerSuite\Services\Notifier;

/**
 * Deliver one notification to every target (#26). Encrypted on the queue:
 * the payload holds webhook URLs and tokens. Targets that fail are logged
 * and retried in a new job of their own (release() would re-send to every
 * target), up to three rounds.
 */
class SendNotificationJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    private const DELAYS = [1 => 30, 2 => 120];

    /**
     * @param list<array<string, string>> $targets
     * @param array{title: string, body: string, level: string, server: ?string, url: ?string} $message
     */
    public function __construct(public array $targets, public array $message, public bool $userSupplied, public int $round = 1) {}

    public function handle(): void
    {
        $failed = [];
        foreach ($this->targets as $t) {
            try {
                Notifier::deliver($t, $this->message, $this->userSupplied);
            } catch (\Throwable $e) {
                logger()->warning('[Server Suite] notification failed', ['target' => Notifier::label($t), 'error' => $e->getMessage()]);
                $failed[] = $t;
            }
        }
        if ($failed !== [] && isset(self::DELAYS[$this->round])) {
            self::dispatch($failed, $this->message, $this->userSupplied, $this->round + 1)->delay(now()->addSeconds(self::DELAYS[$this->round]));
        }
    }
}
