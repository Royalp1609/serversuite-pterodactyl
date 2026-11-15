<?php

namespace ServerSuite\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\NetworkMember;
use ServerSuite\Models\ServerMod;
use ServerSuite\Support\AutoUpgradePolicy;
use ServerSuite\Support\BackupSchedule;
use ServerSuite\Support\MinecraftPing;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Support\SuiteActivity;

/**
 * Opt-in automatic Minecraft version upgrades (#111), Paper only for now.
 *
 * Nobody is watching when this runs, so it holds whenever it can't tell an
 * upgrade is safe. A manual switch goes ahead on a server with no backup; this
 * never does, because no one sees the warning and a world upgrade can't be undone.
 *
 * Each upgrade is a small state machine stored in auto_upgrade_run, moved on by
 * serversuite:auto-upgrades every minute, so a panel restart loses nothing:
 *
 *   backing_up -> reinstalling -> booting -> done
 *
 * Plugins are kept and moved to builds for the new version before the first
 * boot, which is why this takes over from AutoStartService. A failure after the
 * reinstall starts pauses upgrades for that server and names the backup to
 * restore. It never restores by itself.
 */
class AutoUpgradeService
{
    public const RANGES = ['line', 'latest'];

    /** Most newer versions looked up per check, so a Paper outage can't hold up the tick. */
    private const MAX_LOOKUPS = 8;

    public function __construct(
        private PanelServerOps $ops,
        private DaemonFileClient $daemon,
        private ServerVersionService $versions,
        private VersionSwitchService $switch,
        private BackupRotationService $rotation,
        private ModManagerService $mods,
        private RepositoryManager $repositories,
        private StatusCardService $cards,
        private PostSwitchService $post,
        private AutoStartService $autoStart,
        private LoaderDetectionService $loaders,
        private Notifier $notifier,
        private MinecraftPing $ping,
    ) {}

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    public static function panelEnabled(): bool
    {
        return (bool) config('serversuite.auto_upgrades.enabled', true);
    }

    public static function minAgeDays(): int
    {
        return max(0, (int) config('serversuite.auto_upgrades.min_age_days', 7));
    }

    /** Save a server's settings. Turning it on clears a pause. @throws \RuntimeException when it can't be turned on */
    public function configure(object $server, bool $enabled, string $range, int $hour): void
    {
        if (! in_array($range, self::RANGES, true) || $hour < 0 || $hour > 23) {
            throw new \RuntimeException('Pick a valid range and hour.');
        }
        $setting = ModManagerSetting::forServer($server->uuid);
        if ($enabled && ($reason = $this->ineligible($server, $setting)) !== null) {
            throw new \RuntimeException($reason['text']);
        }
        $setting->forceFill([
            'auto_upgrade_enabled' => $enabled,
            'auto_upgrade_range'   => $range,
            'auto_upgrade_hour'    => $hour,
            'auto_upgrade_checked_at' => null,
        ] + ($enabled ? ['auto_upgrade_paused_at' => null] : []))->save();
    }

    /** What the Versions page shows. @return array<string, mixed> */
    public function overview(object $server): array
    {
        $s = ModManagerSetting::forServer($server->uuid);

        return [
            'enabled'       => (bool) $s->auto_upgrade_enabled,
            'range'         => $s->auto_upgrade_range ?: 'line',
            'hour'          => (int) $s->auto_upgrade_hour,
            'timezone'      => BackupSchedule::timezone(),
            'panel_enabled' => self::panelEnabled(),
            'min_age_days'  => self::minAgeDays(),
            'paused'        => $s->auto_upgrade_paused_at !== null,
            'running'       => is_array($s->auto_upgrade_run) ? ['phase' => $s->auto_upgrade_run['phase'], 'to' => $s->auto_upgrade_run['to']] : null,
            'status'        => is_array($s->auto_upgrade_status) ? ['text' => $s->auto_upgrade_status['text'] ?? '', 'at' => $s->auto_upgrade_status['at'] ?? null] : null,
            'blocked'       => $this->ineligible($server, $s)['text'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // The tick
    // -------------------------------------------------------------------------

    public function tick(): void
    {
        $rows = ModManagerSetting::query()
            ->where(fn ($q) => $q->where('auto_upgrade_enabled', true)->orWhereNotNull('auto_upgrade_run'))
            ->get();

        $running = 0;
        foreach ($rows as $s) {
            if ($s->auto_upgrade_run === null) {
                continue;
            }
            $this->guard($s, fn () => $this->step($s), fn (\Throwable $e) => $this->stepThrew($s, $e));
            $running += $s->auto_upgrade_run !== null ? 1 : 0;
        }

        if (! self::panelEnabled()) {
            return;
        }

        $limit = max(1, (int) config('serversuite.auto_upgrades.max_concurrent', 5));
        $every = max(1, (int) config('serversuite.auto_upgrades.check_minutes', 10));
        $hour = BackupSchedule::now()->hour;

        foreach ($rows as $s) {
            if ($running >= $limit) {
                break;
            }
            if (! $s->auto_upgrade_enabled || $s->auto_upgrade_run !== null || $s->auto_upgrade_paused_at !== null || (int) $s->auto_upgrade_hour !== $hour) {
                continue;
            }
            if ($s->auto_upgrade_checked_at !== null && Carbon::parse($s->auto_upgrade_checked_at)->gt(now()->subMinutes($every))) {
                continue;
            }
            $s->forceFill(['auto_upgrade_checked_at' => now()])->save();
            $this->guard($s, fn () => $this->consider($s));
            $running += $s->auto_upgrade_run !== null ? 1 : 0;
        }
    }

    /** One server's problem never stops the others. A step that threw is retried until its phase times out. */
    private function guard(ModManagerSetting $s, callable $work, ?callable $onError = null): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] auto-upgrade step failed', ['server' => $s->server_uuid, 'error' => $e->getMessage()]);
            if ($onError !== null) {
                try {
                    $onError($e);
                } catch (\Throwable $e2) {
                    Log::warning('[Server Suite] auto-upgrade: could not end a failing run', ['server' => $s->server_uuid, 'error' => $e2->getMessage()]);
                }
            }
        }
    }

    /**
     * A step threw. It is tried again each tick, but only until its phase's
     * time limit: then the run ends instead of staying "running" forever with
     * the server stopped (#128). In the backup phase nothing has changed, so
     * it is cancelled. After that the upgrade has failed; if the reinstall is
     * done and the server is stopped it is started on the new version (as the
     * normal path does even when plugin updates fail).
     */
    private function stepThrew(ModManagerSetting $s, \Throwable $e): void
    {
        $run = $s->refresh()->auto_upgrade_run;
        if (! is_array($run) || empty($run['phase_at'])) {
            return;
        }
        [$key, $default] = match ($run['phase'] ?? '') {
            'backing_up'   => ['backup_timeout_minutes', 60],
            'reinstalling' => ['install_timeout_minutes', 30],
            'booting'      => ['boot_timeout_minutes', 15],
            default        => [null, 0],
        };
        if ($key === null || ! $this->timedOut($run, $key, $default)) {
            return;
        }

        $why = 'a step kept failing (' . mb_substr($e->getMessage(), 0, 300) . ').';
        if ($run['phase'] === 'backing_up') {
            $this->cancel($s, 'backup', "Can't upgrade to {$run['to']}: {$why} Nothing was changed.", true);

            return;
        }

        $started = false;
        try {
            $state = $this->ops->runtimeState($s->server_uuid);
            if (($state['install'] ?? null) === null && ($state['power'] ?? null) === 'offline') {
                $this->ops->power($s->server_uuid, 'start');
                $started = true;
            }
        } catch (\Throwable) {
            // leave it; the message says to check it
        }
        $this->fail($s, $run, ucfirst($why) . ($started ? ' The server was started on the new version.' : ''));
    }

    // -------------------------------------------------------------------------
    // Deciding
    // -------------------------------------------------------------------------

    /** Reasons that hold for as long as the server is set up this way. @return array{code: string, text: string}|null */
    private function ineligible(object $server, ModManagerSetting $s): ?array
    {
        $limit = $this->ops->serverBackupLimit($server->uuid);
        if ($limit !== null && $limit <= 0) {
            return ['code' => 'no_backups', 'text' => 'This server has no backup allocation, so it can\'t upgrade automatically.'];
        }
        if (strtolower((string) ($s->loader ?: $this->versions->current($server)['loader'])) !== 'paper') {
            return ['code' => 'loader', 'text' => 'Automatic upgrades only support Paper for now.'];
        }
        if (NetworkMember::query()->where('server_uuid', $server->uuid)->exists()) {
            return ['code' => 'network', 'text' => 'This server is in a Velocity network, so it\'s skipped: an upgrade can lock out players on older game versions.'];
        }

        return null;
    }

    /** Start an upgrade if one is due and everything says it's safe. */
    private function consider(ModManagerSetting $s): void
    {
        $server = $this->server($s->server_uuid);
        if ($server === null) {
            return;
        }

        // Suspended, or node in maintenance (#116): wait, without telling the owner.
        if (\ServerSuite\Support\ServerState::blocker($server) !== null) {
            $this->hold($s, 'unavailable', 'Waiting: the server is suspended or its node is in maintenance.');

            return;
        }

        if (($no = $this->ineligible($server, $s)) !== null) {
            $this->hold($s, $no['code'], $no['text'], true);

            return;
        }

        // What the switch itself will compare against, not only the stored value,
        // which can go stale if the version is changed outside this suite.
        $current = $this->versions->current($server)['game_version'] ?: $s->game_version;
        if (! $current) {
            $this->hold($s, 'version', 'Can\'t tell which Minecraft version this server runs.', true);

            return;
        }

        $list = $this->versions->available('paper')['versions'] ?? [];
        if ($list === []) {
            $this->hold($s, 'versions_down', 'Couldn\'t get Paper\'s version list. Trying again later.');

            return;
        }

        $range = $s->auto_upgrade_range ?: 'line';
        $pick = $this->pickTarget($current, $list, $range);
        if ($pick['target'] === null) {
            $this->holdNoTarget($s, $current, $list, $range, $pick['waiting']);

            return;
        }
        $target = $pick['target'];

        $state = $this->ops->runtimeState($server->uuid);
        if (($state['install'] ?? null) !== null) {
            $this->hold($s, 'busy', "{$target} is due, but the server is busy ({$state['install']}).");

            return;
        }
        if (($state['power'] ?? null) !== 'running') {
            $this->hold($s, 'not_running', "{$target} is due, but the server isn't running. It upgrades on a day it's running at this hour.");

            return;
        }

        $players = $this->playersOnline($server->uuid);
        if ($players === null) {
            // Never upgrade blind (item 66); the notice says what to fix.
            $this->hold($s, 'players_unknown', "{$target} is due, but the panel couldn't read the player count, so it won't upgrade. The panel has to reach the server's game port (check firewalls and NAT between them).", true, $target);

            return;
        }
        if ($players > 0) {
            $this->hold($s, 'players_online', "{$target} is due, but players are online. Trying again later.");

            return;
        }

        if (($blocker = $this->pluginBlocker($s, $target)) !== null) {
            $this->hold($s, 'plugins', $blocker, true, $target);

            return;
        }

        $pf = $this->versions->preflight($server, $target, $pick['build']);
        if (! ($pf['can_proceed'] ?? false) || ($pf['direction'] ?? '') !== 'upgrade') {
            $why = implode(' ', $pf['blockers'] ?? []) ?: "it isn't an upgrade from {$current}.";
            $this->hold($s, 'preflight', "Can't upgrade to {$target}: {$why}", true, $target);

            return;
        }

        // Fill is the only source that names this exact stable build. A jar URL
        // left by an earlier switch would otherwise reinstall the old version.
        $download = $pf['target']['download_url'] ?? null;
        if (! $download && $this->loaders->serverVariable($server, ['DL_PATH'])) {
            $this->hold($s, 'download', "Can't upgrade to {$target}: Paper gave no download address, and this server is pinned to a jar for its current version.", true, $target);

            return;
        }

        $backup = $this->startBackup($s, $target);
        if ($backup === null) {
            return;
        }

        $now = now()->toIso8601String();
        $s->forceFill(['auto_upgrade_run' => [
            'phase' => 'backing_up', 'from' => $current, 'to' => $target, 'build' => $pick['build'], 'download_url' => $download,
            'backup_uuid' => $backup['uuid'], 'backup_name' => $backup['name'], 'started_at' => $now, 'phase_at' => $now,
        ]])->save();
        $this->status($s, 'backing_up', "Upgrading to {$target}: taking the backup.");
    }

    /**
     * The newest allowed version with a stable build that has been out long
     * enough. Never takes a non-stable build, though a manual switch can.
     *
     * @param  string[] $list
     * @return array{target: ?string, build: ?int, waiting: ?string}
     */
    private function pickTarget(string $current, array $list, string $range): array
    {
        $cutoff = now()->subDays(self::minAgeDays());
        $waiting = null;
        foreach (array_slice(AutoUpgradePolicy::candidates($current, $list, $range), 0, self::MAX_LOOKUPS) as $v) {
            $stable = array_filter($this->versions->paperBuilds($v), fn ($b) => strtoupper((string) ($b['channel'] ?? '')) === 'STABLE');
            if ($stable === []) {
                $waiting ??= $v;
                continue;
            }
            // Builds are newest first; the last stable one is when the release became usable.
            $first = end($stable);
            if (empty($first['time']) || Carbon::parse($first['time'])->gt($cutoff)) {
                $waiting ??= $v;
                continue;
            }

            return ['target' => $v, 'build' => (int) reset($stable)['build'], 'waiting' => $waiting];
        }

        return ['target' => null, 'build' => null, 'waiting' => $waiting];
    }

    /** @param string[] $list */
    private function holdNoTarget(ModManagerSetting $s, string $current, array $list, string $range, ?string $waiting): void
    {
        if ($waiting !== null) {
            $this->hold($s, 'waiting', "{$waiting} isn't stable yet or came out less than " . self::minAgeDays() . ' days ago.');

            return;
        }
        $newer = $range === 'line' ? AutoUpgradePolicy::newerLine($current, $list) : null;
        if ($newer !== null) {
            $line = AutoUpgradePolicy::line($current);
            $this->hold($s, 'line_ended', "{$current} is the newest {$line} release. {$newer} is out, but moving to a new line needs the \"Newest version\" setting.", true, $newer);

            return;
        }
        $this->hold($s, 'up_to_date', "Up to date on {$current}.");
    }

    private function playersOnline(string $uuid): ?int
    {
        [$host, $port] = $this->cards->target($uuid);
        if ($host === null || $port === null) {
            return null;
        }
        try {
            $status = $this->ping->ping($host, $port, $host);
        } catch (\Throwable) {
            return null;
        }

        return isset($status['players']['online']) ? (int) $status['players']['online'] : null;
    }

    /** Why the plugins hold this upgrade, or null when each has a build for $target. */
    private function pluginBlocker(ModManagerSetting $s, string $target): ?string
    {
        try {
            $entries = $this->daemon->listDirectory($s->server_uuid, 'plugins');
        } catch (\Throwable) {
            return "Can't upgrade to {$target}: couldn't read the plugins folder.";
        }
        $jars = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['is_file'] ?? false) && preg_match('/\.jar$/i', $name)) {
                $jars[] = $name;
            }
        }

        $mods = ServerMod::query()->where('server_uuid', $s->server_uuid)->get()->keyBy('target_filename');
        $unknown = $missing = [];
        foreach ($jars as $jar) {
            $mod = $mods->get($jar);
            if ($mod === null) {
                $unknown[] = $jar;
                continue;
            }
            try {
                if ($this->repositories->driver($mod->source)->latestVersion($mod->source_id, 'paper', $target) === null) {
                    $missing[] = $mod->mod_name;
                }
            } catch (\Throwable) {
                $unknown[] = $mod->mod_name;
            }
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = 'no build yet for ' . implode(', ', $missing);
        }
        if ($unknown !== []) {
            $parts[] = 'can\'t check ' . implode(', ', $unknown);
        }

        return $parts === [] ? null : "Can't upgrade to {$target}: " . implode('; ', $parts) . '.';
    }

    /** @return array{uuid: ?string, name: string}|null null when it held */
    private function startBackup(ModManagerSetting $s, string $target): ?array
    {
        $name = "Pre-upgrade \u{2192} {$target}";

        // A backup from a try that players interrupted is still good.
        $cutoff = now()->subMinutes(50);
        foreach ($this->ops->listBackups($s->server_uuid) as $b) {
            if (str_contains($b->name, $name) && $b->isSuccessful && $b->completedAt !== null && Carbon::parse($b->completedAt)->gt($cutoff)) {
                return ['uuid' => $b->uuid, 'name' => $b->name];
            }
        }

        $res = $this->rotation->backupNow($s->server_uuid, $name);
        if ($res->isFailed() || $res->isSkipped()) {
            $wait = in_array($res->reason, ['backup_in_progress', 'throttled'], true);
            $this->hold($s, 'backup', "Can't upgrade to {$target}: the backup didn't start (" . ($res->message ?? $res->reason) . ').', ! $wait, $target);

            return null;
        }

        return ['uuid' => $res->created?->uuid, 'name' => $res->created?->name ?? $name];
    }

    // -------------------------------------------------------------------------
    // Moving an upgrade along
    // -------------------------------------------------------------------------

    private function step(ModManagerSetting $s): void
    {
        $run = $s->auto_upgrade_run;
        $server = $this->server($s->server_uuid);
        if ($server === null) {
            $s->forceFill(['auto_upgrade_run' => null])->save();

            return;
        }

        match ($run['phase'] ?? '') {
            'backing_up'   => $this->stepBackup($s, $server, $run),
            'reinstalling' => $this->stepReinstall($s, $server, $run),
            'booting'      => $this->stepBoot($s, $server, $run),
            default        => $s->forceFill(['auto_upgrade_run' => null])->save(),
        };
    }

    /** @param array<string, mixed> $run */
    private function stepBackup(ModManagerSetting $s, object $server, array $run): void
    {
        // Nothing has changed on the server yet, so it can still back out.
        if (! $s->auto_upgrade_enabled || ! self::panelEnabled()) {
            $this->cancel($s, 'cancelled', 'The upgrade was cancelled: automatic upgrades were turned off.');

            return;
        }
        if (\ServerSuite\Support\ServerState::blocker($server) !== null) {
            $this->cancel($s, 'cancelled', 'The upgrade was cancelled: the server was suspended or its node went into maintenance. Nothing was changed.');

            return;
        }

        $backup = collect($this->ops->listBackups($s->server_uuid))->first(fn ($b) => $b->uuid === $run['backup_uuid']);
        if ($backup === null || ($backup->completedAt !== null && ! $backup->isSuccessful)) {
            $this->cancel($s, 'backup', "Can't upgrade to {$run['to']}: the backup failed. Nothing was changed.", true);

            return;
        }
        if ($backup->completedAt === null) {
            if ($this->timedOut($run, 'backup_timeout_minutes', 60)) {
                $this->cancel($s, 'backup', "Can't upgrade to {$run['to']}: the backup didn't finish in time. Nothing was changed.", true);
            }

            return;
        }

        // Things can change while a backup runs. Each of these backs out cleanly.
        if (($this->versions->current($server)['game_version'] ?: $run['from']) !== $run['from']) {
            $this->cancel($s, 'cancelled', 'The upgrade was cancelled: the server\'s version was changed by someone else.');

            return;
        }
        $state = $this->ops->runtimeState($s->server_uuid);
        if (($state['install'] ?? null) !== null || ($state['power'] ?? null) !== 'running') {
            $this->cancel($s, 'not_running', "{$run['to']} is due, but the server stopped during the backup. It tries again next time.");

            return;
        }
        $players = $this->playersOnline($s->server_uuid);
        if ($players === null) {   // item 67: say which it was
            $this->cancel($s, 'players_unknown', "{$run['to']} is due, but after the backup the panel couldn't read the player count. Trying again later.");

            return;
        }
        if ($players > 0) {
            $this->cancel($s, 'players_online', "{$run['to']} is due, but players joined during the backup. Trying again later.");

            return;
        }

        try {
            // Plugins stay, and no wipe: this path never takes anything from the server.
            $out = $this->switch->apply($server, $run['to'], $run['build'], true, false, 'none', $run['download_url'] ?? null);
        } catch (\Throwable $e) {
            $this->fail($s, $run, $e->getMessage());

            return;
        }
        if (($out['status'] ?? '') !== 'reinstalling') {
            $this->fail($s, $run, 'The reinstall didn\'t start.');

            return;
        }

        $this->autoStart->forget($s->server_uuid);   // plugin updates come first; stepReinstall starts it
        $this->advance($s, $run, 'reinstalling');
        $this->status($s, 'reinstalling', "Upgrading to {$run['to']}: reinstalling.");
    }

    /** @param array<string, mixed> $run */
    private function stepReinstall(ModManagerSetting $s, object $server, array $run): void
    {
        $install = $this->ops->runtimeState($s->server_uuid)['install'] ?? null;
        if (is_string($install) && str_contains($install, 'failed')) {
            $this->fail($s, $run, 'The reinstall failed.');

            return;
        }
        if ($install !== null) {
            if ($this->timedOut($run, 'install_timeout_minutes', 30)) {
                $this->fail($s, $run, 'The reinstall didn\'t finish in time.');
            }

            return;
        }

        $this->post->finish($s->server_uuid);
        $run['plugins'] = $this->updatePlugins($server, $run['to']);
        $this->ops->power($s->server_uuid, 'start');
        $this->advance($s, $run, 'booting');
        $this->status($s, 'booting', "Upgrading to {$run['to']}: waiting for the server to start.");
    }

    /** Move plugins to their builds for the new version. A failure is reported, not fatal: most keep working. */
    private function updatePlugins(object $server, string $target): string
    {
        $updated = $failed = [];
        foreach (ServerMod::query()->where('server_uuid', $server->uuid)->get() as $mod) {
            if ($mod->modpack_id !== null || $mod->disabled_at !== null) {
                continue;
            }
            try {
                $latest = $this->repositories->driver($mod->source)->latestVersion($mod->source_id, 'paper', $target);
                if ($latest !== null && $latest->versionNumber !== $mod->installed_version) {
                    $this->mods->update($mod, $server);
                    $updated[] = $mod->mod_name;
                }
            } catch (\Throwable) {
                $failed[] = $mod->mod_name;
            }
        }

        $parts = [];
        if ($updated !== []) {
            $parts[] = 'Updated plugins: ' . implode(', ', $updated) . '.';
        }
        if ($failed !== []) {
            $parts[] = 'Still on the old build: ' . implode(', ', $failed) . '.';
        }

        return implode(' ', $parts);
    }

    /** @param array<string, mixed> $run */
    private function stepBoot(ModManagerSetting $s, object $server, array $run): void
    {
        [$host, $port] = $this->cards->target($s->server_uuid);
        $status = null;
        if ($host !== null && $port !== null) {
            try {
                $status = $this->ping->ping($host, $port, $host);
            } catch (\Throwable) {
                // not up yet
            }
        }
        if ($status === null) {
            if ($this->timedOut($run, 'boot_timeout_minutes', 15)) {
                $this->fail($s, $run, 'The server didn\'t come back up.');
            }

            return;
        }

        $reported = (string) ($status['version']['name'] ?? '');
        $found = preg_match_all('/\d+\.\d+(?:\.\d+)?/', $reported, $m) > 0;
        if ($found && ! in_array($run['to'], $m[0], true)) {
            $this->fail($s, $run, "The server came back up reporting \"{$reported}\", not {$run['to']}.");

            return;
        }
        // A plugin can replace the version text (item 68): it's up, but say the
        // version couldn't be confirmed rather than claim it was.
        $unconfirmed = $found ? '' : " The server reports \"" . mb_substr($reported, 0, 60) . "\" as its version, so the panel couldn't confirm {$run['to']}.";

        $body = trim(($run['plugins'] ?? '') . " Backup from before the upgrade: {$run['backup_name']}." . $unconfirmed);
        $this->notifier->notify($s->server_uuid, 'autoupgrade', "Upgraded to Minecraft {$run['to']}", "Automatically upgraded from {$run['from']}. {$body}", 'info', 'done:' . $run['started_at']);
        SuiteActivity::logFor($s->server_uuid, 'server:serversuite.auto-upgrade', ['from' => $run['from'], 'to' => $run['to'], 'backup' => $run['backup_name']]);
        $s->forceFill(['auto_upgrade_run' => null])->save();
        $this->status($s, 'upgraded', "Upgraded from {$run['from']} to {$run['to']}. {$body}", null);
    }

    // -------------------------------------------------------------------------
    // Recording what happened
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $run */
    private function advance(ModManagerSetting $s, array $run, string $phase): void
    {
        $s->forceFill(['auto_upgrade_run' => ['phase' => $phase, 'phase_at' => now()->toIso8601String()] + $run])->save();
    }

    /** @param array<string, mixed> $run */
    private function timedOut(array $run, string $key, int $default): bool
    {
        return Carbon::parse($run['phase_at'])->lt(now()->subMinutes((int) config("serversuite.auto_upgrades.{$key}", $default)));
    }

    private function cancel(ModManagerSetting $s, string $code, string $text, bool $notify = false): void
    {
        $s->forceFill(['auto_upgrade_run' => null])->save();
        $this->hold($s, $code, $text, $notify);
    }

    /**
     * The reinstall started and something went wrong. Only a person can judge
     * what to do next, so upgrades stop for this server until they turn them on.
     *
     * @param array<string, mixed> $run
     */
    private function fail(ModManagerSetting $s, array $run, string $why): void
    {
        $text = "The upgrade from {$run['from']} to {$run['to']} failed: {$why} Automatic upgrades are paused for this server. To go back, restore the backup \"{$run['backup_name']}\" on the Backups page.";
        $s->forceFill(['auto_upgrade_run' => null, 'auto_upgrade_paused_at' => now()])->save();
        $this->notifier->notify($s->server_uuid, 'autoupgrade', "Upgrade to Minecraft {$run['to']} failed", $text, 'error', 'failed:' . $run['started_at']);
        SuiteActivity::logFor($s->server_uuid, 'server:serversuite.auto-upgrade-failed', ['from' => $run['from'], 'to' => $run['to'], 'backup' => $run['backup_name'], 'reason' => $why]);
        $this->status($s, 'failed', $text, null);
    }

    /**
     * Record why nothing happened. A reason worth telling the owner goes out once,
     * until the reason changes, however many checks in a row it comes up.
     */
    private function hold(ModManagerSetting $s, string $code, string $text, bool $notify = false, ?string $target = null): void
    {
        $key = $code . '|' . ($target ?? $text);
        $sent = $s->auto_upgrade_status['notified'] ?? null;
        if ($notify && $sent !== $key) {
            $this->notifier->notify($s->server_uuid, 'autoupgrade', 'Automatic upgrade on hold', $text, 'warn', $key);
            SuiteActivity::logFor($s->server_uuid, 'server:serversuite.auto-upgrade-held', ['reason' => $code, 'detail' => $text]);
            $sent = $key;
        }
        $this->status($s, $code, $text, $sent);
    }

    /** @param string|null|false $notified what was last sent; false keeps it */
    private function status(ModManagerSetting $s, string $code, string $text, string|null|false $notified = false): void
    {
        $s->forceFill(['auto_upgrade_status' => [
            'code' => $code, 'text' => $text, 'at' => now()->toIso8601String(),
            'notified' => $notified === false ? ($s->auto_upgrade_status['notified'] ?? null) : $notified,
        ]])->save();
    }

    private function server(string $uuid): ?object
    {
        $model = '\\Pterodactyl\\Models\\Server';

        return class_exists($model) ? $model::query()->with('egg')->where('uuid', $uuid)->first() : null;
    }
}
