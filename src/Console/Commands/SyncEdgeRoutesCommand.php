<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\SubdomainService;

class SyncEdgeRoutesCommand extends Command
{
    protected $signature = 'serversuite:edge-sync';

    protected $description = 'Put back edge-router (mc-router) routes for edge subdomains that the router lost or that point elsewhere.';

    public function handle(SubdomainService $subdomains): int
    {
        $fixed = $subdomains->syncEdgeRoutes();
        if ($fixed === null) {
            $this->line('[Server Suite] no edge router configured, or it can\'t be reached.');

            return self::SUCCESS;
        }
        $this->line("[Server Suite] edge routes fixed: {$fixed}");

        return self::SUCCESS;
    }
}
