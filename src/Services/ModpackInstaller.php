<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Log;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\ModpackException;
use ServerSuite\Models\Modpack;
use ServerSuite\Models\ServerMod;
use ServerSuite\Modpacks\MrpackIndex;
use ServerSuite\Support\SuiteActivity;

/**
 * Runs a queued modpack install or update (#23) as a small state machine.
 * InstallModpackJob calls step() until it returns null; a number means "run
 * again in that many seconds" (waiting on Wings, a backup, or the next chunk
 * of files). Every phase is safe to repeat.
 *
 *   stopping   - stop the server (mods rewrite configs on shutdown, which
 *                would undo the pack's overrides)
 *   backing_up - "Pre-modpack" backup, unless the server has backups off
 *   switching / reinstalling - only when the server isn't on the pack's
 *                loader and Minecraft version: wipe, change egg, reinstall
 *   staging    - Wings fetches the .mrpack and unpacks it in /.serversuite/modpack;
 *                its index must match the one the user confirmed
 *   files      - remove what the new version dropped, then download the rest
 *                in chunks (Wings pulls each file from its host)
 *   overrides  - merge overrides/ then server-overrides/ into the server
 *   finishing  - record the pack and its mods, remove the staging folder
 *
 * The server is left stopped; the owner starts it when ready.
 */
class ModpackInstaller
{
    public const STAGING = '/.serversuite/modpack';

    /** Seconds of work per job run before handing over to a fresh job. */
    private const BUDGET = 45;

    public function __construct(
        protected DaemonFileClient $daemon,
        protected PanelServerOps $ops,
        protected FileDeliveryService $delivery,
        protected BackupRotationService $rotation,
        protected LoaderSwitchService $loaderSwitch,
    ) {}

    /** Advance the install. Returns seconds until the next run, or null when finished. */
    public function step(Modpack $pack): ?int
    {
        $started = microtime(true);

        while ($pack->isRunning()) {
            $delay = match ($pack->phase) {
                'stopping'   => $this->stopping($pack),
                'backing_up' => $this->backingUp($pack),
                'switching'  => $this->switching($pack),
                'reinstalling' => $this->reinstalling($pack),
                'staging'    => $this->staging($pack),
                'files'      => $this->files($pack, $started),
                'overrides'  => $this->overrides($pack),
                'finishing'  => $this->finishing($pack),
                default      => throw new ModpackException("Unknown install phase {$pack->phase}."),
            };
            $pack->save();

            if ($delay !== 0 || microtime(true) - $started > self::BUDGET) {
                return $pack->isRunning() ? max(0, (int) $delay) : null;
            }
        }

        return null;
    }

    public function fail(Modpack $pack, string $message): void
    {
        $pack->forceFill([
            'status' => Modpack::STATUS_FAILED, 'error' => mb_substr($message, 0, 2000), 'finished_at' => now(),
        ])->save();
        $this->cleanStaging($pack->server_uuid);
        SuiteActivity::logFor($pack->server_uuid, 'server:serversuite.modpack-failed', [
            'pack' => $pack->target['name'] ?? null, 'version' => $pack->target['version_number'] ?? null, 'error' => $pack->error,
        ]);
    }

    // -------------------------------------------------------------------------

    private function stopping(Modpack $pack): int
    {
        $power = $this->ops->runtimeState($pack->server_uuid)['power'] ?? null;
        if ($power === 'offline') {
            $pack->phase = 'backing_up';

            return 0;
        }

        $state = $pack->state ?? [];
        if (empty($state['stop_sent_at'])) {
            $this->ops->power($pack->server_uuid, 'stop');
            $pack->mergeState(['stop_sent_at' => now()->getTimestamp()]);
        } elseif (now()->getTimestamp() - (int) $state['stop_sent_at'] > 300) {
            throw new ModpackException('The server did not stop within 5 minutes. Stop it from the console and try again.');
        }

        return 5;
    }

    private function backingUp(Modpack $pack): int
    {
        $state = $pack->state ?? [];

        if (($state['backups'] ?? 'yes') === 'disabled') {
            $pack->phase = $this->afterBackup($pack);

            return 0;
        }

        if (empty($state['backup_uuid'])) {
            $name = "Pre-modpack \u{2192} " . ($pack->target['name'] ?? 'pack') . ' ' . ($pack->target['version_number'] ?? '');
            $res = $this->rotation->backupNow($pack->server_uuid, mb_substr($name, 0, 180));

            if ($res->isRotated() && $res->created !== null) {
                $pack->mergeState(['backup_uuid' => $res->created->uuid]);

                return 10;
            }
            if ($res->isFailed() && $res->reason === 'throttled' && ($state['backup_attempts'] ?? 0) < 10) {
                $pack->mergeState(['backup_attempts' => ($state['backup_attempts'] ?? 0) + 1]);

                return 60;
            }
            if (! $res->isFailed() && $res->reason === 'backups_disabled') {
                $pack->phase = $this->afterBackup($pack);

                return 0;
            }

            throw new ModpackException('The pre-modpack backup could not be taken: ' . ($res->message ?: $res->reason) . '. Nothing was changed.');
        }

        $backup = collect($this->ops->listBackups($pack->server_uuid))->firstWhere('uuid', $state['backup_uuid']);
        if ($backup === null) {
            throw new ModpackException('The pre-modpack backup disappeared before it finished. Nothing was changed.');
        }
        if ($backup->completedAt !== null) {
            if (! $backup->isSuccessful) {
                throw new ModpackException('The pre-modpack backup failed. Nothing was changed.');
            }
            $pack->phase = $this->afterBackup($pack);

            return 0;
        }
        if (now()->getTimestamp() - $pack->started_at->getTimestamp() > 3600) {
            throw new ModpackException('The pre-modpack backup took more than an hour. Nothing was changed.');
        }

        return 10;
    }

    private function afterBackup(Modpack $pack): string
    {
        return empty($pack->plan['switch']) ? 'staging' : 'switching';
    }

    /**
     * Stage 2: put the server on the pack's loader and Minecraft version.
     * LoaderSwitchService wipes the server, changes the egg (with the pack's
     * exact loader version) and reinstalls. It wants a completed backup from
     * the last hour, which the pre-modpack backup is.
     */
    private function switching(Modpack $pack): int
    {
        $sw = (array) $pack->plan['switch'];
        $server = \Pterodactyl\Models\Server::query()->where('uuid', $pack->server_uuid)->firstOrFail();

        try {
            $out = $this->loaderSwitch->switchLoader($server, (int) $sw['egg_id'], (string) ($pack->target['minecraft'] ?? ''), true, (array) ($sw['env'] ?? []));
        } catch (\RuntimeException $e) {
            throw new ModpackException('Could not switch the server: ' . $e->getMessage());
        }

        if (($out['status'] ?? null) === 'reinstalling') {
            $pack->mergeState(['switched_at' => now()->getTimestamp()]);
            $pack->phase = 'reinstalling';

            return 15;
        }

        return 5;   // still stopping
    }

    private function reinstalling(Modpack $pack): int
    {
        $install = $this->ops->runtimeState($pack->server_uuid)['install'] ?? null;
        $egg = $pack->plan['switch']['egg_name'] ?? 'the new loader';
        if (in_array($install, ['install_failed', 'reinstall_failed'], true)) {
            throw new ModpackException("The server's reinstall onto {$egg} failed. Check its console; the pre-modpack backup has the old server.");
        }
        if ($install === 'installing') {
            if (now()->getTimestamp() - (int) ($pack->state['switched_at'] ?? now()->getTimestamp()) > 1800) {
                throw new ModpackException("The server's reinstall onto {$egg} took over 30 minutes. Check its console; the pre-modpack backup has the old server.");
            }

            return 15;
        }

        $pack->phase = 'staging';

        return 0;
    }

    private function staging(Modpack $pack): int
    {
        $uuid = $pack->server_uuid;
        $mrpack = (array) ($pack->target['mrpack'] ?? []);

        $this->cleanStaging($uuid);
        $this->daemon->makeDirectory($uuid, self::STAGING);
        $this->delivery->deliverFile($uuid, (string) $mrpack['url'], self::STAGING, 'pack.mrpack', (int) ($mrpack['size'] ?? 0));
        $this->daemon->decompress($uuid, self::STAGING, 'pack.mrpack');

        $index = $this->daemon->readFile($uuid, self::STAGING . '/' . ($pack->plan['index_file'] ?? 'modrinth.index.json'), 16 * 1048576);
        if ($index === null || ! hash_equals((string) ($pack->plan['index_sha1'] ?? ''), sha1($index))) {
            throw new ModpackException('The modpack Wings downloaded is not the one you confirmed. Nothing was changed.');
        }

        $pack->phase = 'files';

        return 0;
    }

    private function files(Modpack $pack, float $started): int
    {
        $uuid = $pack->server_uuid;
        $plan = $pack->plan ?? [];
        $state = $pack->state ?? [];

        // First run: remove what the new version dropped, and copies of the
        // pack's mods installed outside it (they'd load twice).
        if (empty($state['removed'])) {
            $byDir = [];
            foreach ((array) ($plan['remove'] ?? []) as $path) {
                $byDir['/' . ltrim(dirname('/' . $path), '/')][] = basename($path);
            }
            foreach ((array) ($plan['replace'] ?? []) as $r) {
                $byDir[$r['dir']][] = $r['file'];
            }
            foreach ($byDir as $dir => $names) {
                try {
                    $this->daemon->deleteFiles($uuid, $dir, array_values(array_unique($names)));
                } catch (\Throwable $e) {
                    Log::warning("[Server Suite] modpack: could not remove old files in {$dir}: {$e->getMessage()}");
                }
            }
            $pack->mergeState(['removed' => true]);
        }

        $items = (array) ($plan['install'] ?? []);
        $failures = $pack->failures ?? [];
        $made = (array) ($state['dirs'] ?? []);

        while ($pack->progress_done < count($items)) {
            $item = $items[$pack->progress_done];
            $dir = '/' . ltrim(dirname('/' . $item['path']), '/');
            $file = basename($item['path']);

            try {
                if (! in_array($dir, $made, true)) {
                    $this->daemon->makeDirectory($uuid, $dir);
                    $made[] = $dir;
                }
                // Replace, never write next to, an existing file.
                if (collect($this->daemon->listDirectory($uuid, $dir))->contains(fn ($e) => $e['name'] === $file)) {
                    $this->daemon->deleteFiles($uuid, $dir, [$file]);
                }
                $this->delivery->deliverFile($uuid, (string) $item['url'], $dir, $file, (int) $item['size']);
            } catch (\Throwable $e) {
                $failures[] = ['path' => $item['path'], 'reason' => mb_substr($e->getMessage(), 0, 300)];
            }

            $pack->progress_done++;
            $pack->failures = $failures;
            $pack->mergeState(['dirs' => $made]);

            if (microtime(true) - $started > self::BUDGET) {
                return 0;   // step() hands over to a fresh job
            }
        }

        $pack->phase = 'overrides';

        return 0;
    }

    private function overrides(Modpack $pack): int
    {
        $uuid = $pack->server_uuid;
        $present = collect($this->daemon->listDirectory($uuid, self::STAGING))->pluck('name')->all();

        $placed = [];
        foreach ((array) ($pack->plan['override_dirs'] ?? ['overrides', 'server-overrides']) as $folder) {
            if (in_array($folder, $present, true)) {
                // Zips made on Windows often carry mode 000; the server couldn't read them.
                $this->delivery->normalisePermissions($uuid, self::STAGING . '/' . $folder);
                $placed = array_merge($placed, $this->delivery->mergeInto($uuid, self::STAGING . '/' . $folder, '/'));
            }
        }
        $pack->mergeState(['placed_overrides' => count(array_unique($placed))]);
        $pack->phase = 'finishing';

        return 0;
    }

    private function finishing(Modpack $pack): ?int
    {
        $plan = $pack->plan ?? [];
        $failed = array_column($pack->failures ?? [], 'path');

        // Files the pack now owns: downloads that landed, unchanged ones, and overrides.
        $files = [];
        foreach ((array) ($plan['unchanged'] ?? []) as $f) {
            $files[] = ['path' => $f['path'], 'sha1' => $f['sha1'], 'size' => $f['size'], 'kind' => 'download'];
        }
        foreach ((array) ($plan['install'] ?? []) as $f) {
            if (! in_array($f['path'], $failed, true)) {
                $files[] = ['path' => $f['path'], 'sha1' => $f['sha1'], 'size' => $f['size'], 'kind' => 'download'];
            }
        }
        foreach ((array) ($plan['overrides'] ?? []) as $path) {
            $files[] = ['path' => $path, 'sha1' => null, 'size' => 0, 'kind' => 'override'];
        }

        $this->recordMods($pack, $failed);

        $t = $pack->target ?? [];
        $pack->forceFill([
            'project_id' => $t['project_id'] ?? null, 'version_id' => $t['version_id'] ?? null,
            'name' => $t['name'] ?? null, 'version_number' => $t['version_number'] ?? null,
            'icon_url' => $t['icon_url'] ?? null, 'minecraft' => $t['minecraft'] ?? null,
            'loader' => $t['loader'] ?? null, 'loader_version' => $t['loader_version'] ?? null,
            'files' => $files, 'installed_at' => now(),
            'status' => Modpack::STATUS_INSTALLED, 'phase' => 'done', 'finished_at' => now(),
        ]);
        $this->cleanStaging($pack->server_uuid);

        // Crash help (#25) names the pack and its backup if the server then fails to start.
        app(CrashHelpService::class)->recordChange($pack->server_uuid, 'install modpack ' . ($pack->name ?? '') . ' ' . ($pack->version_number ?? ''),
            ! empty($pack->state['backup_uuid']) ? ['uuid' => $pack->state['backup_uuid'], 'name' => 'Pre-modpack backup'] : null);

        SuiteActivity::logFor($pack->server_uuid, 'server:serversuite.modpack-installed', [
            'pack' => $pack->name, 'version' => $pack->version_number,
            'files' => count($plan['install'] ?? []), 'failed' => count($failed),
        ]);

        return null;
    }

    /** Manifest: the pack's mods are tracked mods linked to the pack. */
    private function recordMods(Modpack $pack, array $failed): void
    {
        $plan = $pack->plan ?? [];

        // Rows for files the new version removed.
        foreach ((array) ($plan['remove'] ?? []) as $path) {
            ServerMod::forServer($pack->server_uuid)->where('modpack_id', $pack->id)
                ->where('target_filename', basename($path))->delete();
        }

        foreach ((array) ($plan['install'] ?? []) as $f) {
            $mod = $f['mod'] ?? null;
            if ($mod === null || in_array($f['path'], $failed, true)) {
                continue;
            }
            ServerMod::query()->updateOrCreate(
                ['server_uuid' => $pack->server_uuid, 'source' => $plan['source'] ?? 'modrinth', 'source_id' => $mod['project_id']],
                [
                    'mod_name'          => $mod['name'],
                    'mod_slug'          => $mod['slug'],
                    'icon_url'          => $mod['icon_url'],
                    'page_url'          => $mod['page_url'],
                    'installed_version' => $mod['version_number'],
                    'game_version'      => $pack->target['minecraft'] ?? null,
                    'loader'            => $pack->target['loader'] ?? null,
                    'target_filename'   => basename($f['path']),
                    'install_path'      => '/' . ltrim(dirname('/' . $f['path']), '/'),
                    'dependencies'      => $mod['dependencies'] ?? [],
                    'installed_by'      => $pack->requested_by,
                    'modpack_id'        => $pack->id,
                ],
            );
        }
    }

    private function cleanStaging(string $uuid): void
    {
        try {
            $this->daemon->deleteFiles($uuid, '/.serversuite', ['modpack']);
        } catch (\Throwable) {
            // not there yet, or already gone
        }
    }
}
