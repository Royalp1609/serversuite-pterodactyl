<?php

namespace ServerSuite\Console\Commands;

use Illuminate\Console\Command;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\CrashHelpService;
use ServerSuite\Services\Notifier;

/**
 * #19: tell a server's channel when it crashes after a mod change. Crash
 * help (#18) only looks when someone opens the Mods page; this looks every
 * few minutes, for servers that changed mods in the last two days and have
 * somewhere to send it. Each change is reported once.
 */
class WatchCrashesCommand extends Command
{
    protected $signature = 'serversuite:watch-crashes';

    protected $description = 'Notify when a server crashes after a mod change (only servers with notifications set up).';

    public function handle(CrashHelpService $crashHelp, Notifier $notifier): int
    {
        if (! class_exists(\Pterodactyl\Models\Server::class)) {
            return self::SUCCESS;
        }
        $since = now()->subHours(48);
        $candidates = ModManagerSetting::query()->whereNotNull('last_change')->get()->filter(function (ModManagerSetting $s) use ($since) {
            $c = $s->last_change;

            return is_array($c) && empty($c['dismissed']) && empty($c['notified']) && ! empty($c['at'])
                && \Illuminate\Support\Carbon::parse($c['at'])->gt($since);
        });

        foreach ($candidates as $s) {
            $cfg = $notifier->forServer($s->server_uuid);
            if ($cfg['targets'] === [] || ! ($cfg['events']['crash'] ?? true)) {
                continue;
            }
            $server = \Pterodactyl\Models\Server::query()->where('uuid', $s->server_uuid)->first();
            $crash = $server ? $crashHelp->analyze($server) : null;
            if ($crash === null) {
                continue;
            }
            $suspects = array_map(fn ($x) => ($x['strength'] === 'weak' ? 'maybe ' : '') . ($x['mod_name'] ?? $x['id'] ?? $x['file']), $crash['suspects']);
            $notifier->notify($s->server_uuid, 'crash', 'Crashed after: ' . ($crash['change']['label'] ?? 'a mod change'), trim(implode("\n", array_filter([
                $crash['summary'],
                $suspects === [] ? null : 'Likely cause: ' . implode(', ', array_slice($suspects, 0, 5)),
                ! empty($crash['change']['backup']['name']) ? 'Backup from before the change: ' . $crash['change']['backup']['name'] : null,
                'Details on the Mods page (crash help) and the Errors page.',
            ]))), 'error', (string) $crash['change']['at']);
            $s->forceFill(['last_change' => ['notified' => true] + $crash['change']])->save();
        }

        return self::SUCCESS;
    }
}
