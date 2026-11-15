<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\SubdomainService;

class MoveSubdomainsCommand extends Command
{
    protected $signature = 'serversuite:move-subdomains {--full : look at every subdomain, not just the ones waiting to move}';

    protected $description = 'Point subdomains at their server\'s new primary allocation once the server has started on it.';

    public function handle(SubdomainService $subdomains): int
    {
        // Every minute: only subdomains already waiting to move. Every 15
        // minutes (or --full): all of them, to notice allocation changes the
        // server hook missed (#141/#139 item 59).
        $moved = $subdomains->applyPendingMoves((bool) $this->option('full') || now()->minute % 15 === 0);
        if ($moved > 0) {
            $this->info("[Server Suite] Moved {$moved} subdomain" . ($moved === 1 ? '' : 's') . '.');
        }

        return self::SUCCESS;
    }
}
