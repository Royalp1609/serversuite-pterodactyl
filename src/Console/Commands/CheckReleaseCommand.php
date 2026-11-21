<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\ReleaseCheckService;

class CheckReleaseCommand extends Command
{
    protected $signature = 'serversuite:check-release';

    protected $description = 'Check GitHub for a newer Server Suite release and tell the admin (no panel data is sent).';

    public function handle(ReleaseCheckService $releases): int
    {
        if (! $releases->enabled()) {
            $this->line('[Server Suite] release check is turned off on the admin page.');

            return self::SUCCESS;
        }
        $releases->check();
        $n = $releases->notice();
        $this->line($n === null
            ? '[Server Suite] ' . $releases->installedVersion() . ' is the newest release known.'
            : "[Server Suite] {$n['version']} is out" . ($n['security'] ? ' (security release)' : '') . " - this panel runs {$n['installed']}: {$n['url']}");

        return self::SUCCESS;
    }
}
