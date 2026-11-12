<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\SubdomainService;

class CheckDnsTokensCommand extends Command
{
    protected $signature = 'serversuite:check-dns-tokens';

    protected $description = 'Check each subdomain domain\'s Cloudflare token and warn the admin before it expires or after it stops working.';

    public function handle(SubdomainService $subdomains): int
    {
        foreach ($subdomains->checkDomainTokens() as $d) {
            $w = SubdomainService::tokenWarning($d);
            $this->warn("[Server Suite] {$w['title']}");
        }

        return self::SUCCESS;
    }
}
