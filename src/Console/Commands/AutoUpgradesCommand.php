<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Services\AutoUpgradeService;

/** Moves upgrades in flight on a step, and starts any that are due (#111). */
class AutoUpgradesCommand extends Command
{
    protected $signature = 'serversuite:auto-upgrades';

    protected $description = 'Run opt-in automatic Minecraft version upgrades.';

    public function handle(AutoUpgradeService $upgrades): int
    {
        $upgrades->tick();

        return self::SUCCESS;
    }
}
