<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\ServerDataCleaner;
use ServerSuite\Services\SubdomainService;

/**
 * #117: when a server is deleted and Cloudflare doesn't remove its records,
 * its subdomains (and the settings row holding its own-domain token) are
 * kept so the names aren't left stuck in DNS. This tries again (hourly from
 * the scheduler), then removes the rest of that server's data as the delete
 * would have.
 *
 * #149: it also removes whatever else deleted servers left behind (servers
 * deleted before the delete hook cleaned up, or while the extension was
 * off), so nothing keeps retrying a server that is gone.
 * `serversuite:prune-orphans` does the same by hand.
 */
class RetrySubdomainCleanupCommand extends Command
{
    protected $signature = 'serversuite:retry-subdomain-cleanup {--dry-run : Only count what deleted servers left behind}';

    protected $description = 'Remove the DNS records, subdomains and other Server Suite data of servers that were deleted.';

    public function handle(SubdomainService $subdomains, ServerDataCleaner $cleaner): int
    {
        if ($this->option('dry-run')) {
            $this->info('[Server Suite] ' . $subdomains->cleanupOrphans(true) . ' subdomain(s) of deleted servers waiting; '
                . array_sum($cleaner->orphans(true)) . ' other row(s) left behind.');

            return self::SUCCESS;
        }

        $uuids = Subdomain::query()->whereNotIn('server_uuid', DB::table('servers')->select('uuid'))->distinct()->pluck('server_uuid');
        if ($uuids->isNotEmpty()) {
            $subdomains->cleanupOrphans();
            $left = $subdomains->cleanupOrphans(true);
            $this->info("[Server Suite] Retried the DNS cleanup of {$uuids->count()} deleted server(s); {$left} subdomain(s) still waiting.");
        }

        // Settings of servers that still have subdomains are kept (the token).
        $removed = array_sum($cleaner->orphans());
        if ($removed > 0) {
            $this->info("[Server Suite] Removed {$removed} row(s) left behind by deleted servers.");
        }

        return self::SUCCESS;
    }
}
