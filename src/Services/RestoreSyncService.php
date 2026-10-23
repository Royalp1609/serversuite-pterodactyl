<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\BackupModList;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Modpack;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ServerModPendingUpdate;
use ServerSuite\Support\SuiteActivity;

/**
 * Brings the mod list back in line after a backup restore (#60).
 *
 * Every backup Server Suite takes saves the mod list with it (snapshot()).
 * When a restore finishes, wherever it was started (our Backups tab, the
 * panel's own Backups page, the client API), SyncAfterRestoreJob calls
 * completed(): it works out which backup was restored and puts the saved
 * list back, then checks it against the disk. A backup with no saved list
 * gets the safe part of Reconcile instead: rows whose file is gone are
 * forgotten and untracked jars with an exact hash match are adopted.
 *
 * The result is kept for the Mods tab's banner (lastResult()) and written to
 * the server's Activity.
 */
class RestoreSyncService
{
    /** Fields of a server_mods row the saved list keeps. */
    private const MOD_FIELDS = [
        'source', 'source_id', 'mod_name', 'mod_slug', 'icon_url', 'page_url', 'installed_version',
        'game_version', 'loader', 'target_filename', 'install_path', 'dependencies', 'installed_by', 'disabled_at',
    ];

    /** Fields of the modpack row that describe the installed pack. */
    private const PACK_FIELDS = [
        'source', 'project_id', 'version_id', 'name', 'version_number', 'icon_url',
        'minecraft', 'loader', 'loader_version', 'files', 'installed_at',
    ];

    /** How long the Mods tab shows what a restore changed. */
    private const RESULT_DAYS = 7;

    public function __construct(
        protected DaemonFileClient $daemon,
        protected ReconciliationService $reconcile,
    ) {}

    // -------------------------------------------------------------------------
    // Saving the list with a backup
    // -------------------------------------------------------------------------

    /** Save the server's mod list with a backup Server Suite just took. Never throws. */
    public function snapshot(string $serverUuid, string $backupUuid): void
    {
        try {
            $pack = Modpack::forServer($serverUuid);
            $settings = ModManagerSetting::forServer($serverUuid);

            BackupModList::query()->updateOrCreate(['backup_uuid' => $backupUuid], [
                'server_uuid' => $serverUuid,
                'mods'        => ServerMod::forServer($serverUuid)->get()->map(fn (ServerMod $m) => $this->modRow($m))->values()->all(),
                'modpack'     => $pack?->hasInstalledPack() ? $this->packRow($pack) : null,
                'settings'    => ['loader' => $settings->loader, 'game_version' => $settings->game_version, 'install_path' => $settings->install_path],
            ]);
        } catch (\Throwable $e) {
            Log::warning("[Server Suite] could not save the mod list with backup {$backupUuid}: {$e->getMessage()}");
        }
    }

    /** The backup was deleted or rotated out. */
    public function forget(string $backupUuid): void
    {
        BackupModList::query()->where('backup_uuid', $backupUuid)->delete();
    }

    /**
     * Drop saved lists whose backup no longer exists (deleted on the panel's
     * own Backups page, for example).
     *
     * @param string[] $existing backup uuids the server still has
     */
    public function prune(string $serverUuid, array $existing): void
    {
        BackupModList::query()->where('server_uuid', $serverUuid)->whereNotIn('backup_uuid', $existing)->delete();
    }

    // -------------------------------------------------------------------------
    // Noticing a restore
    // -------------------------------------------------------------------------

    /** Our Backups tab started a restore; the panel's own restores are found in its activity log. */
    public function noteRestore(string $serverUuid, string $backupUuid, bool $truncate): void
    {
        Cache::put($this->markerKey($serverUuid), [
            'backup' => $backupUuid, 'truncate' => $truncate, 'at' => now()->getTimestamp(),
        ], now()->addDays(2));
    }

    /**
     * A restore on this server finished (its status left restoring_backup).
     * Returns what changed, or null when there was nothing to do (the
     * restore failed, or the backup can't be identified).
     *
     * @return array<string, mixed>|null
     */
    public function completed(object $server): ?array
    {
        $marker = Cache::pull($this->markerKey($server->uuid));
        $restore = $this->restoreFromActivity($server);

        // The newer of the two: our tab's marker or the panel's own log entry.
        if (is_array($marker) && ($restore === null || $marker['at'] >= $restore['at'])) {
            $restore = $marker;
        }
        if ($restore === null) {
            Log::info("[Server Suite] a restore finished on {$server->uuid} but the backup could not be identified; mod list left as it was");

            return null;
        }
        if ($this->restoreFailed($server, (int) $restore['at'])) {
            return null;
        }

        return $this->apply($server, (string) $restore['backup'], (bool) $restore['truncate']);
    }

    // -------------------------------------------------------------------------
    // Bringing the list back in line
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> what changed */
    public function apply(object $server, string $backupUuid, bool $truncate): array
    {
        $uuid = $server->uuid;
        $saved = BackupModList::query()->where('backup_uuid', $backupUuid)->first();
        $before = ServerMod::forServer($uuid)->get();
        $beforeKeys = $before->map(fn (ServerMod $m) => $this->key($m->toArray()))->all();

        $forgotten = [];
        $adopted = [];
        if ($saved !== null) {
            DB::transaction(fn () => $this->putBack($uuid, $saved, $before, $truncate));
            $forgotten = $this->forgetMissing($uuid);
        } else {
            // No saved list (an older backup, or one taken outside Server
            // Suite): only the clear cases, the rest stays in Reconcile.
            $forgotten = $this->forgetMissing($uuid);
            $adopted = $this->adoptExact($server);
        }

        // Update badges are recomputed by the next update check.
        ServerModPendingUpdate::query()->whereIn('server_mod_id', $before->pluck('id')->merge(ServerMod::forServer($uuid)->pluck('id'))->all())->delete();

        $after = ServerMod::forServer($uuid)->get();
        $afterKeys = $after->map(fn (ServerMod $m) => $this->key($m->toArray()))->all();
        $back = $after->filter(fn (ServerMod $m) => ! in_array($this->key($m->toArray()), $beforeKeys, true))->pluck('mod_name')->values()->all();
        $gone = $before->filter(fn (ServerMod $m) => ! in_array($this->key($m->toArray()), $afterKeys, true))->pluck('mod_name')->values()->all();

        $name = $this->backupName($uuid, $backupUuid);
        $result = [
            'at'         => now()->toIso8601String(),
            'backup'     => ['uuid' => $backupUuid, 'name' => $name],
            'saved_list' => $saved !== null,
            'truncate'   => $truncate,
            'back'       => $back,
            'gone'       => $gone,
            'forgotten'  => $forgotten,
            'adopted'    => $adopted,
            'untracked'  => $this->untrackedCount($uuid),
        ];
        Cache::put($this->resultKey($uuid), $result, now()->addDays(self::RESULT_DAYS));

        // Crash help (#25): the last change is now the restore, not the mod change it undid.
        app(CrashHelpService::class)->recordChange($uuid, 'restore backup ' . ($name ?? $backupUuid));

        SuiteActivity::logFor($uuid, 'server:serversuite.mods-restored', [
            'backup' => $name ?? $backupUuid, 'back' => count($back), 'gone' => count($gone),
        ]);

        return $result;
    }

    /** @return array<string, mixed>|null what the last restore changed, for the Mods tab */
    public function lastResult(string $serverUuid): ?array
    {
        $r = Cache::get($this->resultKey($serverUuid));

        return is_array($r) ? $r : null;
    }

    public function dismiss(string $serverUuid): void
    {
        Cache::forget($this->resultKey($serverUuid));
    }

    // -------------------------------------------------------------------------

    /**
     * Replace the rows with the saved ones. Without "delete all files first"
     * the files of mods added after the backup are still there, so their
     * rows stay (forgetMissing() drops any whose file the restore did remove).
     */
    private function putBack(string $uuid, BackupModList $saved, $before, bool $truncate): void
    {
        $s = (array) ($saved->settings ?? []);
        if ($s !== []) {
            ModManagerSetting::forServer($uuid)->forceFill([
                'loader' => $s['loader'] ?? null, 'game_version' => $s['game_version'] ?? null, 'install_path' => $s['install_path'] ?? null,
            ])->save();
        }

        $pack = Modpack::forServer($uuid);
        $packId = null;
        if ($pack !== null && $pack->isRunning()) {
            $packId = $pack->id;   // an install is running: leave it alone
        } elseif ($saved->modpack !== null) {
            $pack ??= new Modpack(['server_uuid' => $uuid]);
            $pack->forceFill(array_intersect_key($saved->modpack, array_flip(self::PACK_FIELDS)) + [
                'status' => Modpack::STATUS_INSTALLED, 'phase' => 'done', 'error' => null, 'failures' => [],
            ])->save();
            $packId = $pack->id;
        } elseif ($pack !== null) {
            $pack->delete();   // the backup is from before any pack
        }

        $savedKeys = array_map(fn ($r) => $this->key($r), $saved->mods);
        $keep = $truncate ? collect() : $before->filter(fn (ServerMod $m) => ! in_array($this->key($m->toArray()), $savedKeys, true) && $m->modpack_id === null);
        $keepRows = $keep->map(fn (ServerMod $m) => $this->modRow($m))->values()->all();

        ServerMod::forServer($uuid)->delete();
        foreach (array_merge($saved->mods, $keepRows) as $row) {
            $fromPack = ! empty($row['from_modpack']);
            unset($row['from_modpack']);
            (new ServerMod())->forceFill(array_intersect_key($row, array_flip(self::MOD_FIELDS)) + [
                'server_uuid' => $uuid, 'modpack_id' => $fromPack ? $packId : null,
            ])->save();
        }
    }

    /** Forget rows whose file isn't on the disk (enabled or disabled). @return string[] mod names */
    private function forgetMissing(string $uuid): array
    {
        $forgotten = [];
        foreach (ServerMod::forServer($uuid)->get()->groupBy(fn (ServerMod $m) => $m->install_path ?: ModManagerSetting::forServer($uuid)->effectiveInstallPath()) as $dir => $mods) {
            try {
                $names = collect($this->daemon->listDirectory($uuid, (string) $dir))->where('is_file', true)->pluck('name')->all();
            } catch (\Throwable) {
                $names = [];   // the folder is gone: so are its mods
            }
            foreach ($mods as $m) {
                $file = (string) $m->target_filename;
                if ($file !== '' && ! in_array($file, $names, true) && ! in_array($file . '.disabled', $names, true)) {
                    $forgotten[] = $m->mod_name;
                    $m->delete();
                }
            }
        }

        return $forgotten;
    }

    /** Adopt untracked jars whose hash matches a known mod version exactly. @return string[] mod names */
    private function adoptExact(object $server): array
    {
        $adopted = [];
        try {
            foreach ($this->reconcile->reconcile($server)['untracked'] as $u) {
                $id = $u['identified'] ?? null;
                if (($id['confidence'] ?? null) !== 'exact') {
                    continue;
                }
                try {
                    $adopted[] = $this->reconcile->adopt($server, $u['filename'], $id['source'], $id['mod_id'], $id['version_id'], 0)->mod_name;
                } catch (\Throwable $e) {
                    Log::info("[Server Suite] restore sync: could not adopt {$u['filename']}: {$e->getMessage()}");
                }
            }
        } catch (\Throwable $e) {
            Log::warning("[Server Suite] restore sync: reconcile failed on {$server->uuid}: {$e->getMessage()}");
        }

        return $adopted;
    }

    /** Jars in the mods folder that nothing tracks (what Reconcile would list). */
    private function untrackedCount(string $uuid): int
    {
        $dir = ModManagerSetting::forServer($uuid)->effectiveInstallPath();
        try {
            $jars = collect($this->daemon->listDirectory($uuid, $dir))
                ->filter(fn ($e) => ($e['is_file'] ?? false) && str_ends_with(strtolower((string) $e['name']), '.jar'))->pluck('name');
        } catch (\Throwable) {
            return 0;
        }
        $tracked = ServerMod::forServer($uuid)->pluck('target_filename')->all();
        $packFiles = array_map(fn ($f) => basename((string) $f['path']), (array) (Modpack::forServer($uuid)?->files ?? []));

        return $jars->reject(fn ($n) => in_array($n, $tracked, true) || in_array($n, $packFiles, true))->count();
    }

    /**
     * The panel's own record of the last restore on this server (client API,
     * the panel's Backups page): event server:backup.restore, with the backup
     * as a subject and the truncate flag as a property.
     *
     * @return array{backup: string, truncate: bool, at: int}|null
     */
    private function restoreFromActivity(object $server): ?array
    {
        try {
            $log = DB::table('activity_logs as l')
                ->join('activity_log_subjects as s', 's.activity_log_id', '=', 'l.id')
                ->where('s.subject_type', 'server')->where('s.subject_id', $server->id)
                ->where('l.event', 'server:backup.restore')
                ->where('l.timestamp', '>=', now()->subDays(2))
                ->orderByDesc('l.id')->first(['l.id', 'l.properties', 'l.timestamp']);
            if ($log === null) {
                return null;
            }
            $backupId = DB::table('activity_log_subjects')->where('activity_log_id', $log->id)->where('subject_type', 'backup')->value('subject_id');
            $backup = $backupId ? DB::table('backups')->where('id', $backupId)->value('uuid') : null;
            if (! $backup) {
                return null;
            }
            $props = json_decode((string) $log->properties, true) ?: [];

            return ['backup' => (string) $backup, 'truncate' => (bool) ($props['truncate'] ?? false), 'at' => strtotime((string) $log->timestamp) ?: 0];
        } catch (\Throwable) {
            return null;   // no activity tables (tests, or a panel that changed them)
        }
    }

    /** Wings reported the restore failed (the panel logs "server.backup.restore-failed", sic). */
    private function restoreFailed(object $server, int $since): bool
    {
        try {
            return DB::table('activity_logs as l')
                ->join('activity_log_subjects as s', 's.activity_log_id', '=', 'l.id')
                ->where('s.subject_type', 'server')->where('s.subject_id', $server->id)
                ->whereIn('l.event', ['server.backup.restore-failed', 'server:backup.restore-failed'])
                ->where('l.timestamp', '>=', date('Y-m-d H:i:s', $since))
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function backupName(string $uuid, string $backupUuid): ?string
    {
        try {
            return collect(app(\ServerSuite\Contracts\PanelServerOps::class)->listBackups($uuid))->firstWhere('uuid', $backupUuid)?->name;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function modRow(ServerMod $m): array
    {
        $row = [];
        foreach (self::MOD_FIELDS as $f) {
            $row[$f] = $m->{$f};
        }
        $row['disabled_at'] = $m->disabled_at?->format('Y-m-d H:i:s');
        $row['from_modpack'] = $m->modpack_id !== null;

        return $row;
    }

    /** @return array<string, mixed> */
    private function packRow(Modpack $p): array
    {
        $row = [];
        foreach (self::PACK_FIELDS as $f) {
            $row[$f] = $p->{$f};
        }
        $row['installed_at'] = $p->installed_at?->format('Y-m-d H:i:s');

        return $row;
    }

    /** One mod across lists: its source and project, or the file for hand-tracked ones. */
    private function key(array $row): string
    {
        $source = (string) ($row['source'] ?? '');

        return $source . ':' . ($source === 'manual' ? ($row['target_filename'] ?? '') : ($row['source_id'] ?? ''));
    }

    private function markerKey(string $uuid): string
    {
        return "serversuite.restore.marker.{$uuid}";
    }

    private function resultKey(string $uuid): string
    {
        return "serversuite.restore.result.{$uuid}";
    }
}
