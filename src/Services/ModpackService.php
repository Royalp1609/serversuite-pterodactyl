<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Drivers\ModrinthDriver;
use ServerSuite\Exceptions\ModpackException;
use ServerSuite\Jobs\InstallModpackJob;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Modpack;
use ServerSuite\Models\ServerMod;
use ServerSuite\Modpacks\MrpackIndex;
use ServerSuite\Support\RepositoryManager;

/**
 * Modrinth modpacks (#23): find a pack, preview what installing (or updating
 * to) a version would do on this server, and start the queued install.
 *
 * The preview downloads the .mrpack to the panel once (cached) to read its
 * index; the install itself has Wings fetch everything (ModpackInstaller).
 * The server must already run the pack's loader and Minecraft version; the
 * preview says what to switch to otherwise (switching for you is a follow-up).
 */
class ModpackService
{
    public function __construct(
        protected RepositoryManager $repositories,
        protected LoaderDetectionService $detection,
    ) {}

    public function driver(): ModrinthDriver
    {
        $driver = $this->repositories->driver('modrinth');
        if (! $driver instanceof ModrinthDriver || ! $driver->isAvailable()) {
            throw new ModpackException('Modrinth is turned off on this panel.');
        }

        return $driver;
    }

    /** Loaders a Modrinth pack can target; plugin servers (Paper etc.) have no packs. */
    public const LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    /** Whether packs can be offered on this server at all. */
    public function supports(object $server): bool
    {
        return in_array($this->serverContext($server)['loader'], self::LOADERS, true);
    }

    /**
     * Packs with a build for the server's loader and Minecraft version.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(object $server, string $query, int $offset = 0, int $limit = 24, ?string $sort = null): array
    {
        $ctx = $this->serverContext($server);

        return array_map(fn ($r) => $r->toArray(), $this->driver()->searchModpacks(trim($query), $limit, $offset, $sort, $ctx['loader'], $ctx['minecraft']));
    }

    /**
     * A pack's versions with a .mrpack, newest first. Each is marked
     * compatible (it runs on this server) or not, with the reason.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versions(string $projectId, ?object $server = null): array
    {
        $ctx = $server ? $this->serverContext($server) : ['loader' => null, 'minecraft' => null];
        $out = [];
        foreach ($this->driver()->modpackVersions($projectId) as $v) {
            if ($v['mrpack'] === null) {
                continue;
            }
            $reason = null;
            if ($ctx['loader'] && ! in_array($ctx['loader'], array_map('strtolower', $v['loaders']), true)) {
                $reason = 'For ' . implode(', ', array_map('ucfirst', $v['loaders'])) . ', not ' . ucfirst($ctx['loader']);
            } elseif ($ctx['minecraft'] && ! in_array($ctx['minecraft'], $v['game_versions'], true)) {
                $reason = 'For Minecraft ' . implode(', ', $v['game_versions']) . ', not ' . $ctx['minecraft'];
            }
            $out[] = $v + ['compatible' => $reason === null, 'reason' => $reason];
        }

        // Compatible versions first, each group newest first.
        usort($out, fn ($a, $b) => $b['compatible'] <=> $a['compatible']);

        return $out;
    }

    /**
     * The server's loader and Minecraft version, as the Mods tab sees them.
     *
     * @return array{loader: ?string, minecraft: ?string}
     */
    public function serverContext(object $server): array
    {
        $s = ModManagerSetting::forServer($server->uuid);
        $loader = $s->loader;
        $mc = $s->game_version;
        if (! $loader || ! $mc) {
            try {
                $d = $this->detection->detectDetailed($server);
                $loader = $loader ?: $d->loader;
                $mc = $mc ?: $d->gameVersion;
            } catch (\Throwable) {
            }
        }

        return ['loader' => $loader ? strtolower($loader) : null, 'minecraft' => $mc ?: null];
    }

    /**
     * The installed pack and the install running or last run, for the tab
     * (polled while an install runs). Null when the server never had one.
     *
     * @return array<string, mixed>|null
     */
    public function status(object $server): ?array
    {
        $pack = Modpack::forServer($server->uuid);
        if ($pack === null) {
            return null;
        }

        return [
            'status'    => $pack->status,
            'phase'     => $pack->phase,
            'done'      => $pack->progress_done,
            'total'     => $pack->progress_total,
            'failures'  => array_slice($pack->failures ?? [], 0, 50),
            'failed'    => count($pack->failures ?? []),
            'error'     => $pack->error,
            'target'    => ['name' => $pack->target['name'] ?? null, 'version_number' => $pack->target['version_number'] ?? null],
            'installed' => $pack->hasInstalledPack() ? [
                'project_id' => $pack->project_id, 'name' => $pack->name, 'version_number' => $pack->version_number,
                'icon_url' => $pack->icon_url, 'minecraft' => $pack->minecraft, 'loader' => $pack->loader,
                'at' => $pack->installed_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Everything the confirm screen shows, plus the plan start() stores.
     *
     * @return array<string, mixed>
     *
     * @throws ModpackException
     */
    public function preview(object $server, string $projectId, string $versionId): array
    {
        $driver = $this->driver();
        $version = $driver->modpackVersion($versionId);
        if ($version === null || $version['project_id'] !== $projectId || $version['mrpack'] === null) {
            throw new ModpackException('That modpack version was not found.');
        }
        $project = $driver->get($projectId);

        [$index, $overrides] = $this->readPack($version['mrpack']);
        $context = $this->serverContext($server);
        $current = Modpack::forServer($server->uuid);

        $blockers = [];
        if ($index->loader === null) {
            $blockers[] = 'This pack has no mod loader (Fabric, Quilt, Forge or NeoForge), so it can\'t run as a modded server.';
        } elseif ($context['loader'] !== $index->loader || $context['minecraft'] !== $index->minecraft) {
            $blockers[] = sprintf(
                'This pack needs %s %s; the server runs %s %s. Switch it on the Versions tab first, then come back.',
                ucfirst($index->loader), $index->minecraft,
                $context['loader'] ? ucfirst($context['loader']) : 'an unknown loader', $context['minecraft'] ?? '(unknown version)',
            );
        }
        if ($current?->isRunning()) {
            $blockers[] = 'A modpack install is already running on this server.';
        }

        // What changes on disk.
        $old = collect($current?->files ?? [])->keyBy('path');
        $install = $unchanged = [];
        foreach ($index->installable() as $f) {
            $prev = $old->get($f['path']);
            if ($prev !== null && ($prev['sha1'] ?? null) === $f['sha1']) {
                $unchanged[] = ['path' => $f['path'], 'sha1' => $f['sha1'], 'size' => $f['size'], 'kind' => 'download'];
            } else {
                $install[] = ['path' => $f['path'], 'url' => $f['url'], 'size' => $f['size'], 'sha1' => $f['sha1']];
            }
        }

        // Name the mods (one lookup for the whole pack) so the manifest can
        // track them and installed copies outside the pack get replaced.
        $known = $this->identify($driver, $install);
        $replace = [];
        foreach ($install as &$item) {
            if (isset($known[$item['sha1']])) {
                $item['mod'] = $known[$item['sha1']];
            }
        }
        unset($item);
        $packProjects = array_filter(array_column(array_column($install, 'mod'), 'project_id'));
        if ($packProjects !== []) {
            $replace = ServerMod::forServer($server->uuid)->where('source', 'modrinth')
                ->whereIn('source_id', $packProjects)->whereNull('modpack_id')->get()
                ->map(fn (ServerMod $m) => ['dir' => (string) ($m->install_path ?: '/mods'), 'file' => (string) $m->target_filename, 'name' => $m->mod_name])
                ->filter(fn ($r) => $r['file'] !== '' && ! in_array(ltrim($r['dir'] . '/' . $r['file'], '/'), array_column($install, 'path'), true))
                ->values()->all();
        }

        $newPaths = array_merge(array_column($index->installable(), 'path'), $overrides);
        $remove = $old->filter(fn ($f) => ! in_array($f['path'], $newPaths, true)
            && (($f['kind'] ?? 'download') === 'download' || str_ends_with(strtolower($f['path']), '.jar')))
            ->pluck('path')->values()->all();

        $backupLimit = null;
        try {
            $backupLimit = app(\ServerSuite\Contracts\PanelServerOps::class)->serverBackupLimit($server->uuid);
        } catch (\Throwable) {
        }

        return [
            'pack' => [
                'project_id' => $projectId,
                'name'       => $project?->name ?? $index->name,
                'icon_url'   => $project?->iconUrl,
                'page_url'   => $project ? 'https://modrinth.com/modpack/' . $project->slug : null,
            ],
            'version' => [
                'id'             => $version['id'],
                'version_number' => $version['version_number'],
                'mrpack'         => $version['mrpack'],
            ],
            'requires'  => ['loader' => $index->loader, 'loader_version' => $index->loaderVersion, 'minecraft' => $index->minecraft],
            'server'    => $context,
            'blockers'  => $blockers,
            'current'   => $current?->hasInstalledPack() ? [
                'name' => $current->name, 'version_number' => $current->version_number,
                'same_pack' => $current->project_id === $projectId,
            ] : null,
            'counts' => [
                'install'   => count($install),
                'unchanged' => count($unchanged),
                'remove'    => count($remove),
                'replace'   => count($replace),
                'overrides' => count($overrides),
                'skipped'   => count($index->skipped()),
            ],
            'download_bytes' => array_sum(array_column($install, 'size')),
            'skipped'        => array_map(fn ($f) => ['path' => $f['path'], 'reason' => $f['reason']], $index->skipped()),
            'replace'        => $replace,
            'remove'         => $remove,
            'backups'        => $backupLimit === 0 ? 'disabled' : 'yes',
            'plan' => [
                'index_sha1' => $index->sha1,
                'install'    => $install,
                'unchanged'  => $unchanged,
                'remove'     => $remove,
                'replace'    => $replace,
                'overrides'  => $overrides,
            ],
        ];
    }

    /**
     * Queue the install. Re-runs the preview, so the plan is checked against
     * the server as it is now, not when the page was opened.
     *
     * @throws ModpackException
     */
    public function start(object $server, string $projectId, string $versionId, int $userId): Modpack
    {
        $p = $this->preview($server, $projectId, $versionId);
        if ($p['blockers'] !== []) {
            throw new ModpackException($p['blockers'][0]);
        }

        $pack = Modpack::forServer($server->uuid) ?? new Modpack(['server_uuid' => $server->uuid, 'source' => 'modrinth']);
        $pack->fill([
            'status'         => Modpack::STATUS_INSTALLING,
            'phase'          => 'stopping',
            'target'         => [
                'project_id' => $projectId, 'version_id' => $versionId,
                'name' => $p['pack']['name'], 'version_number' => $p['version']['version_number'],
                'icon_url' => $p['pack']['icon_url'], 'mrpack' => $p['version']['mrpack'],
            ] + $p['requires'],
            'plan'           => $p['plan'],
            'progress_done'  => 0,
            'progress_total' => count($p['plan']['install']),
            'failures'       => [],
            'state'          => ['backups' => $p['backups']],
            'error'          => null,
            'requested_by'   => $userId,
            'started_at'     => now(),
            'finished_at'    => null,
        ])->save();

        InstallModpackJob::dispatch($pack->id);

        return $pack;
    }

    /**
     * Download the .mrpack (once per file hash) and read its index and the
     * list of override files.
     *
     * @param array{url: string, size: int, sha1: ?string, filename: string} $mrpack
     * @return array{0: MrpackIndex, 1: string[]}
     *
     * @throws ModpackException
     */
    public function readPack(array $mrpack): array
    {
        if (! MrpackIndex::allowedUrl($mrpack['url'])) {
            throw new ModpackException('The modpack file is not hosted on Modrinth.');
        }
        $max = (int) config('serversuite.modpacks.max_mrpack_bytes', 268435456);
        if ($mrpack['size'] > $max) {
            throw new ModpackException('The modpack file is larger than this panel allows (' . intdiv($max, 1048576) . ' MB).');
        }

        $key = 'serversuite.mrpack.' . ($mrpack['sha1'] ?: sha1($mrpack['url']));
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = $this->download($mrpack, $max);
            Cache::put($key, $cached, now()->addDay());
        }

        return [MrpackIndex::parse($cached['index']), $cached['overrides']];
    }

    /** @return array{index: string, overrides: string[]} */
    private function download(array $mrpack, int $max): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ssmrpack');
        try {
            $res = Http::timeout(120)->withOptions(['sink' => $tmp])->get($mrpack['url']);
            if (! $res->successful()) {
                throw new ModpackException('Could not download the modpack (HTTP ' . $res->status() . ').');
            }
            clearstatcache(true, $tmp);
            if (filesize($tmp) === 0 && $res->body() !== '') {
                file_put_contents($tmp, $res->body());   // a client that ignored the sink
            }
            if (filesize($tmp) > $max) {
                throw new ModpackException('The modpack file is larger than this panel allows.');
            }
            if ($mrpack['sha1'] && ! hash_equals($mrpack['sha1'], sha1_file($tmp))) {
                throw new ModpackException('The downloaded modpack does not match its published hash.');
            }

            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new ModpackException('The modpack file is not a valid .mrpack archive.');
            }
            $index = $zip->getFromName('modrinth.index.json');
            if ($index === false) {
                $zip->close();
                throw new ModpackException('The modpack has no modrinth.index.json.');
            }

            // Files the overrides folders will place (server-overrides win).
            $overrides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                foreach (['overrides/', 'server-overrides/'] as $prefix) {
                    if (str_starts_with($name, $prefix) && ! str_ends_with($name, '/')) {
                        $rel = substr($name, strlen($prefix));
                        if (MrpackIndex::safePath($rel)) {
                            $overrides[$rel] = true;
                        }
                    }
                }
            }
            $zip->close();

            return ['index' => $index, 'overrides' => array_keys($overrides)];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Modrinth project + version for each mod jar, keyed by sha1.
     *
     * @param array<int, array{path: string, sha1: string}> $files
     * @return array<string, array{project_id: string, name: string, slug: string, icon_url: ?string, page_url: ?string, version_id: string, version_number: string, dependencies: array}>
     */
    private function identify(ModrinthDriver $driver, array $files): array
    {
        $jars = array_filter($files, fn ($f) => str_ends_with(strtolower($f['path']), '.jar'));
        try {
            $found = $driver->identifyFiles(array_column($jars, 'sha1'));
        } catch (\Throwable) {
            return [];   // names are nice to have; the install works without them
        }

        return array_map(fn ($hit) => [
            'project_id'     => $hit['mod']->id,
            'name'           => $hit['mod']->name,
            'slug'           => $hit['mod']->slug,
            'icon_url'       => $hit['mod']->iconUrl,
            'page_url'       => $hit['mod']->pageUrl,
            'version_id'     => $hit['version']->versionId,
            'version_number' => $hit['version']->versionNumber,
            'dependencies'   => $hit['version']->dependencies,
        ], $found);
    }
}
