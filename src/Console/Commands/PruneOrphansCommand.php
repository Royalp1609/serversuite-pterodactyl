<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\ServerDataCleaner;
use ServerSuite\Services\SubdomainService;

class PruneOrphansCommand extends Command
{
    protected $signature = 'serversuite:prune-orphans {--dry-run : Only count what would be removed}';

    protected $description = 'Remove Server Suite data (mod lists, settings, tasks, subdomains and their DNS) left behind by deleted servers.';

    public function handle(ServerDataCleaner $cleaner, SubdomainService $subdomains): int
    {
        $dry = (bool) $this->option('dry-run');
        // Subdomains first: their DNS records need the settings row's token.
        $counts = array_filter(['server_suite_subdomains' => $subdomains->cleanupOrphans($dry)] + $cleaner->orphans($dry));

        if ($counts === []) {
            $this->info('[Server Suite] Nothing left behind by deleted servers.');

            return self::SUCCESS;
        }

        foreach ($counts as $table => $n) {
            $this->line(sprintf('  %s %d row%s from %s', $dry ? 'would remove' : 'removed', $n, $n === 1 ? '' : 's', $table));
        }

        return self::SUCCESS;
    }
}
