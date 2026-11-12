<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\SubdomainService;

/**
 * #117: when a server is deleted and Cloudflare doesn't remove its records,
 * its subdomains are kept so the names aren't left stuck in DNS. This tries
 * again (hourly from the scheduler) and removes each row once its records
 * are gone.
 */
class RetrySubdomainCleanupCommand extends Command
{
    protected $signature = 'serversuite:retry-subdomain-cleanup {--dry-run : Only count the subdomains of deleted servers}';

    protected $description = 'Remove the DNS records and subdomains of servers that were deleted while Cloudflare could not remove them.';

    public function handle(SubdomainService $subdomains): int
    {
        $n = $subdomains->cleanupOrphans((bool) $this->option('dry-run'));
        $left = $this->option('dry-run') ? $n : $subdomains->cleanupOrphans(true);
        if ($n > 0) {
            $this->info("[Server Suite] {$n} subdomain" . ($n === 1 ? '' : 's') . " of deleted servers; {$left} still waiting.");
        }

        return self::SUCCESS;
    }
}
