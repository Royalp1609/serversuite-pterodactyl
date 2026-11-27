<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Exceptions\SubdomainException;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\SubdomainService;

/**
 * Move existing subdomains onto the edge, or back to direct records (#2).
 * New claims follow the edge setting by themselves; this is for the ones
 * made before it was turned on (or to leave the edge).
 */
class ConvertEdgeSubdomainsCommand extends Command
{
    protected $signature = 'serversuite:edge-convert
        {to : "edge" or "direct"}
        {--server= : only this server (UUID)}
        {--dry-run : say what would change, change nothing}';

    protected $description = 'Move existing Java Minecraft subdomains onto the edge router, or back to direct records.';

    public function handle(SubdomainService $subdomains): int
    {
        $to = strtolower((string) $this->argument('to'));
        if (! in_array($to, ['edge', 'direct'], true)) {
            $this->error('Say "edge" or "direct".');

            return self::INVALID;
        }
        $query = Subdomain::query()->orderBy('id');
        $to === 'edge' ? $query->whereNull('edge_backend') : $query->whereNotNull('edge_backend');
        if ($this->option('server')) {
            $query->where('server_uuid', (string) $this->option('server'));
        }
        $dry = (bool) $this->option('dry-run');
        $failed = 0;
        foreach ($query->get() as $sub) {
            try {
                $what = $to === 'edge' ? $subdomains->convertToEdge($sub, $dry) : $subdomains->convertToDirect($sub, $dry);
                $this->line("{$sub->fqdn}: {$what}");
            } catch (SubdomainException $e) {
                $failed++;
                $this->error($e->getMessage());
            }
        }
        $this->line($dry ? 'Dry run: nothing was changed.' : ($failed ? "{$failed} could not be converted." : 'Done.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
