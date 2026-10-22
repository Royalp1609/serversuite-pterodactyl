<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\SubdomainService;

class MoveSubdomainsCommand extends Command
{
    protected $signature = 'serversuite:move-subdomains';

    protected $description = 'Point subdomains at their server\'s new primary allocation once the server has started on it.';

    public function handle(SubdomainService $subdomains): int
    {
        $moved = $subdomains->applyPendingMoves();
        if ($moved > 0) {
            $this->info("[Server Suite] Moved {$moved} subdomain" . ($moved === 1 ? '' : 's') . '.');
        }

        return self::SUCCESS;
    }
}
