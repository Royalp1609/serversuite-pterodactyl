<?php

namespace ServerSuite\Services;

use Illuminate\Support\Carbon;
use ServerSuite\Contracts\PanelServerOps;

/**
 * The backup taken before a mod change (#24). start() decides whether one
 * is needed and starts it; the caller waits for state() to say done before
 * touching any file, so the backup never captures a half-changed server.
 *
 * The tab is plain HTTP, so the decision is handed over as a clearance: a
 * short-lived token (10 minutes once ready; one change can be several calls,
 * such as a mod and its unused dependencies), scoped to the server, that the change endpoints demand
 * whenever the server's setting is "always" or "ask". "always" can't be
 * skipped by calling the endpoint directly, and "ask" needs an answer.
 */
class PreChangeBackup
{
    public const MODES = ['ask', 'always', 'never'];

    public function __construct(
        protected PanelServerOps $ops,
        protected BackupRotationService $rotation,
    ) {}

    /**
     * @return array{status: string, uuid?: string, name?: string, message?: string}
     *   status: started  - backup running, wait for state() === 'done'
     *           recent   - one completed in the last few minutes; go ahead
     *           disabled - the server can't have backups; go ahead
     *           failed   - couldn't take one; don't change anything
     */
    public function start(string $serverUuid, string $label, bool $mayEvict = true): array
    {
        try {
            if ($this->ops->serverBackupLimit($serverUuid) === 0) {
                return ['status' => 'disabled'];
            }

            $minutes = (int) config('serversuite.backups.pre_change_skip_minutes', 30);
            $recent = collect($this->ops->listBackups($serverUuid))
                ->filter(fn ($b) => $b->isSuccessful && $b->completedAt !== null
                    && Carbon::parse($b->completedAt)->gt(now()->subMinutes($minutes)))
                ->sortByDesc('completedAt')->first();
            if ($recent !== null) {
                return ['status' => 'recent', 'uuid' => $recent->uuid, 'name' => $recent->name];
            }

            $name = mb_substr("Pre-mod-change \u{2192} {$label}", 0, 180);
            $res = $this->rotation->backupNow($serverUuid, $name, $mayEvict);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'message' => $e->getMessage()];
        }

        if ($res->isRotated() && $res->created !== null) {
            return ['status' => 'started', 'uuid' => $res->created->uuid, 'name' => $res->created->name];
        }
        if (! $res->isFailed() && $res->reason === 'backups_disabled') {
            return ['status' => 'disabled'];
        }

        return ['status' => 'failed', 'message' => $res->message ?: (string) $res->reason];
    }

    /** always | ask | never for a server (default ask). */
    public static function mode(string $serverUuid): string
    {
        $m = (string) \ServerSuite\Models\ModManagerSetting::forServer($serverUuid)->pre_change_backup;

        return in_array($m, self::MODES, true) ? $m : 'ask';
    }

    /**
     * Decide and start: returns the clearance token, and the backup if one
     * is being taken. $take is the user's answer when the mode is "ask".
     *
     * @return array{token: ?string, status: string, backup: ?string, message?: string}
     */
    public function clear(string $serverUuid, string $label, ?bool $take, bool $mayEvict = true): array
    {
        $mode = self::mode($serverUuid);
        if ($mode === 'ask' && $take === null) {
            return ['token' => null, 'status' => 'ask', 'backup' => null];
        }
        $r = ($mode === 'never' || ($mode === 'ask' && $take === false)) ? ['status' => 'skipped'] : $this->start($serverUuid, $label, $mayEvict);
        if ($r['status'] === 'failed') {
            return ['token' => null, 'status' => 'failed', 'backup' => null, 'message' => $r['message'] ?? 'unknown error'];
        }
        $token = bin2hex(random_bytes(16));
        \Illuminate\Support\Facades\Cache::put(self::key($serverUuid, $token), [
            'backup' => $r['uuid'] ?? null, 'name' => $r['name'] ?? null, 'pending' => $r['status'] === 'started',
        ], now()->addMinutes(60));

        return ['token' => $token, 'status' => $r['status'], 'backup' => $r['name'] ?? null];
    }

    /** waiting | ready | failed | unknown, for the tab to poll. */
    public function clearanceState(string $serverUuid, string $token): string
    {
        $c = \Illuminate\Support\Facades\Cache::get(self::key($serverUuid, $token));
        if (! is_array($c)) {
            return 'unknown';
        }
        if (! $c['pending']) {
            return 'ready';
        }

        return match ($this->state($serverUuid, (string) $c['backup'])) {
            'done' => 'ready', 'running' => 'waiting', default => 'failed',
        };
    }

    /**
     * Use a clearance for a change: whether the change may run (with the
     * backup's name, if any), or the reason it may not.
     *
     * @return array{ok: bool, backup: ?string, backup_uuid?: ?string, message: ?string}
     */
    public function redeem(string $serverUuid, ?string $token): array
    {
        if (self::mode($serverUuid) === 'never') {
            return ['ok' => true, 'backup' => null, 'backup_uuid' => null, 'message' => null];
        }
        if ($token === null || $token === '' || $this->clearanceState($serverUuid, $token) !== 'ready') {
            return ['ok' => false, 'backup' => null, 'message' => 'This server backs up before mod changes. Ask for the backup first (or wait for it to finish).'];
        }
        $key = self::key($serverUuid, $token);
        $c = \Illuminate\Support\Facades\Cache::get($key);
        if (! is_array($c)) {
            return ['ok' => false, 'backup' => null, 'message' => 'That clearance has expired. Try again.'];
        }
        if (empty($c['ready_at'])) {
            // Ready now: valid for 10 more minutes, however many calls the change takes.
            \Illuminate\Support\Facades\Cache::put($key, ['ready_at' => now()->toIso8601String(), 'pending' => false] + $c, now()->addMinutes(10));
        }

        return ['ok' => true, 'backup' => $c['name'], 'backup_uuid' => $c['backup'], 'message' => null];
    }

    private static function key(string $serverUuid, string $token): string
    {
        return 'serversuite:prechange:' . $serverUuid . ':' . (preg_match('/^[a-f0-9]{32}$/', $token) ? $token : 'invalid');
    }

    /** running | done | failed */
    public function state(string $serverUuid, string $backupUuid): string
    {
        $b = collect($this->ops->listBackups($serverUuid))->firstWhere('uuid', $backupUuid);
        if ($b === null) {
            return 'failed';
        }
        if ($b->completedAt === null) {
            return 'running';
        }

        return $b->isSuccessful ? 'done' : 'failed';
    }
}
