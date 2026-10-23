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
 * Modpacks (#23) from Modrinth (.mrpack) or CurseForge (manifest.json; needs
 * the panel's CurseForge API key): find a pack, preview what installing (or
 * updating to) a version would do on this server, and start the queued install.
 *
 * The preview downloads the pack to the panel once (cached) to read its
 * index; the install itself has Wings fetch everything (ModpackInstaller).
 * A pack for another loader or Minecraft version switches the server first
 * (stage 2): wipe, change egg with the pack's loader version, reinstall. That
 * needs the Versions tab's switch permissions and a second confirmation.
 */
class ModpackService
{
    public function __construct(
        protected RepositoryManager $repositories,
        protected LoaderDetectionService $detection,
        protected EggManagerService $eggs,
    ) {}

    /** Egg variable that pins each loader's version (the pelican-eggs Minecraft eggs; Quilt's egg has none). */
    private const LOADER_VERSION_VARS = [
        'fabric'   => 'LOADER_VERSION',
        'quilt'    => 'LOADER_VERSION',
        'forge'    => 'FORGE_VERSION',
        'neoforge' => 'NEOFORGE_VERSION',
    ];

    public const SOURCES = ['modrinth' => 'Modrinth', 'curseforge' => 'CurseForge'];

    public function driver(string $source = 'modrinth'): ModrinthDriver|\ServerSuite\Drivers\CurseForgeDriver
    {
        if (! isset(self::SOURCES[$source])) {
            throw new ModpackException('Unknown modpack source.');
        }
        $driver = $this->repositories->driver($source);
        if (! $driver->isAvailable()) {
            throw new ModpackException($source === 'curseforge'
                ? 'CurseForge needs an API key on this panel (CURSEFORGE_API_KEY, or the admin setting).'
                : 'Modrinth is turned off on this panel.');
        }

        return $driver;
    }

    /** @return array<string, string> sources this panel can use, key => label */
    public function sources(): array
    {
        return array_filter(self::SOURCES, function ($label, $key) {
            try {
                return $this->repositories->driver($key)->isAvailable();
            } catch (\Throwable) {
                return false;
            }
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Loaders a Modrinth pack can target; plugin servers (Paper etc.) have no packs. */
    public const LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    /** Whether packs can be offered on this server at all. */
    public function supports(object $server): bool
    {
        return in_array($this->serverContext($server)['loader'], self::LOADERS, true);
    }

    /**
     * Packs with a build for the server's loader and Minecraft version, or
     * with $anyVersion, packs for any loader a switch can reach.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(object $server, string $query, int $offset = 0, int $limit = 24, ?string $sort = null, bool $anyVersion = false, string $source = 'modrinth'): array
    {
        $ctx = $this->serverContext($server);
        // CurseForge filters on one loader only, so "any version" there means no loader filter.
        [$loader, $mc] = $anyVersion ? [$source === 'curseforge' ? null : self::LOADERS, null] : [$ctx['loader'], $ctx['minecraft']];

        return array_map(fn ($r) => $r->toArray(), $this->driver($source)->searchModpacks(trim($query), $limit, $offset, $sort, $loader, $mc));
    }

    /** The panel's egg for a loader slug, among the loader eggs the Versions tab offers (#36). */
    public function eggFor(string $loader): ?array
    {
        return collect($this->eggs->minecraftLoaders())->firstWhere('loader', $loader);
    }

    /**
     * A pack's versions with a .mrpack, newest first. Each is marked
     * compatible (it runs on this server) or not, with the reason.
     *
     * @return array<int, array<string, mixed>>
     */
    public function versions(string $projectId, ?object $server = null, string $source = 'modrinth'): array
    {
        $ctx = $server ? $this->serverContext($server) : ['loader' => null, 'minecraft' => null];
        $out = [];
        foreach ($this->driver($source)->modpackVersions($projectId) as $v) {
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
                'project_id' => $pack->project_id, 'source' => $pack->source ?: 'modrinth', 'name' => $pack->name, 'version_number' => $pack->version_number,
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
    public function preview(object $server, string $projectId, string $versionId, bool $canSwitch = false, string $source = 'modrinth'): array
    {
        $driver = $this->driver($source);
        $version = $source === 'curseforge' ? $driver->modpackVersion($versionId, $projectId) : $driver->modpackVersion($versionId);
        if ($version === null || $version['project_id'] !== $projectId) {
            throw new ModpackException('That modpack version was not found.');
        }
        if ($version['mrpack'] === null) {
            throw new ModpackException('This pack version can\'t be downloaded by other apps (its author turned that off).');
        }
        $project = $driver->get($projectId);

        [$index, $overrides] = $this->readPack($version['mrpack'], $source);
        $context = $this->serverContext($server);
        $current = Modpack::forServer($server->uuid);

        $blockers = [];
        $switch = null;
        if ($index->loader === null) {
            $blockers[] = 'This pack has no mod loader (Fabric, Quilt, Forge or NeoForge), so it can\'t run as a modded server.';
        } elseif ($context['loader'] !== $index->loader || $context['minecraft'] !== $index->minecraft) {
            $needs = sprintf('This pack needs %s %s; the server runs %s %s.',
                ucfirst($index->loader), $index->minecraft,
                $context['loader'] ? ucfirst($context['loader']) : 'an unknown loader', $context['minecraft'] ?? '(unknown version)');
            $egg = $canSwitch ? $this->eggFor($index->loader) : null;
            if (! $canSwitch) {
                $blockers[] = $needs . ' Switching the server needs the permissions to change its version (Startup, Docker image, Reinstall and Create backup).';
            } elseif ($egg === null) {
                $blockers[] = $needs . ' This panel has no ' . ucfirst($index->loader) . ' egg to switch to; ask a panel admin to add one.';
            } elseif ($why = \ServerSuite\Support\ReinstallGuard::blocker($server)) {
                $blockers[] = $needs . ' ' . $why;
            } else {
                // Stage 2: switch the server as part of the install.
                $var = self::LOADER_VERSION_VARS[$index->loader] ?? null;
                $value = $index->loader === 'forge' ? "{$index->minecraft}-{$index->loaderVersion}" : (string) $index->loaderVersion;
                $switch = [
                    'egg_id'   => (int) $egg['egg_id'],
                    'egg_name' => (string) $egg['egg_name'],
                    'from'     => $context,
                    'env'      => $var && $index->loaderVersion ? [$var => $value] : [],
                ];
            }
        }
        if ($current?->isRunning()) {
            $blockers[] = 'A modpack install is already running on this server.';
        }

        // What changes on disk. A switch wipes the server first, so then
        // it's a fresh install: nothing is unchanged, replaced or removed.
        $old = $switch ? collect() : collect($current?->files ?? [])->keyBy('path');
        $install = $unchanged = [];
        foreach ($index->installable() as $f) {
            $prev = $old->get($f['path']);
            if ($prev !== null && ($prev['sha1'] ?? null) === $f['sha1']) {
                $unchanged[] = ['path' => $f['path'], 'sha1' => $f['sha1'], 'size' => $f['size'], 'kind' => 'download'];
            } else {
                $install[] = ['path' => $f['path'], 'url' => $f['url'], 'size' => $f['size'], 'sha1' => $f['sha1']]
                    + (isset($f['curseforge']) ? ['curseforge' => $f['curseforge']] : []);
            }
        }

        // Name the mods (one lookup for the whole pack) so the manifest can
        // track them and installed copies outside the pack get replaced.
        $known = $source === 'curseforge' ? $this->identifyCurseForge($driver, $install) : $this->identify($driver, $install);
        $replace = [];
        foreach ($install as &$item) {
            if (isset($known[$item['sha1']])) {
                $item['mod'] = $known[$item['sha1']];
            }
        }
        unset($item);
        $packProjects = array_filter(array_column(array_column($install, 'mod'), 'project_id'));
        if ($packProjects !== [] && $switch === null) {
            $replace = ServerMod::forServer($server->uuid)->where('source', $source)
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

        if ($switch !== null && $backupLimit === 0) {
            $blockers[] = 'Switching the server needs a backup first, and this server has backups turned off.';
            $switch = null;
        }

        return [
            'pack' => [
                'project_id' => $projectId,
                'name'       => $project?->name ?? $index->name,
                'icon_url'   => $project?->iconUrl,
                'page_url'   => $project ? ($source === 'curseforge' ? $project->pageUrl : 'https://modrinth.com/modpack/' . $project->slug) : null,
                'source'     => $source,
            ],
            'version' => [
                'id'             => $version['id'],
                'version_number' => $version['version_number'],
                'mrpack'         => $version['mrpack'],
            ],
            'requires'  => ['loader' => $index->loader, 'loader_version' => $index->loaderVersion, 'minecraft' => $index->minecraft],
            'server'    => $context,
            'blockers'  => $blockers,
            'switch'    => $switch === null ? null : ['egg_name' => $switch['egg_name'], 'from' => $switch['from']],
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
                'source'        => $source,
                'index_file'    => $index->indexFile,
                'override_dirs' => $index->overrideDirs,
                'index_sha1' => $index->sha1,
                'install'    => $install,
                'unchanged'  => $unchanged,
                'remove'     => $remove,
                'replace'    => $replace,
                'overrides'  => $overrides,
                'switch'     => $switch,
            ],
        ];
    }

    /**
     * Queue the install. Re-runs the preview, so the plan is checked against
     * the server as it is now, not when the page was opened.
     *
     * @throws ModpackException
     */
    public function start(object $server, string $projectId, string $versionId, int $userId, bool $canSwitch = false, bool $wipeConfirmed = false, string $source = 'modrinth'): Modpack
    {
        $p = $this->preview($server, $projectId, $versionId, $canSwitch, $source);
        if ($p['blockers'] !== []) {
            throw new ModpackException($p['blockers'][0]);
        }
        if ($p['switch'] !== null && ! $wipeConfirmed) {
            throw new ModpackException('Installing this pack switches the server and deletes everything on it. Confirm that first.');
        }

        $pack = Modpack::forServer($server->uuid) ?? new Modpack(['server_uuid' => $server->uuid]);
        $pack->fill([
            'source'         => $source,
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
    public function readPack(array $mrpack, string $source = 'modrinth'): array
    {
        $curseforge = $source === 'curseforge';
        if (! MrpackIndex::allowedUrl($mrpack['url'], $curseforge ? MrpackIndex::CURSEFORGE_HOSTS : null)) {
            throw new ModpackException($curseforge ? 'The modpack file is not hosted on CurseForge.' : 'The modpack file is not hosted on Modrinth.');
        }
        $max = (int) config('serversuite.modpacks.max_mrpack_bytes', 268435456);
        if ($mrpack['size'] > $max) {
            throw new ModpackException('The modpack file is larger than this panel allows (' . intdiv($max, 1048576) . ' MB).');
        }

        $key = 'serversuite.mrpack.' . $source . '.' . ($mrpack['sha1'] ?: sha1($mrpack['url']));
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            $cached = $this->download($mrpack, $max, $curseforge);
            Cache::put($key, $cached, now()->addDay());
        }

        if (! $curseforge) {
            return [MrpackIndex::parse($cached['index']), $cached['overrides']];
        }

        // CurseForge: resolve the manifest's file ids (download URLs, names, hashes).
        $ids = array_column((array) (json_decode($cached['index'], true)['files'] ?? []), 'fileID');

        return [MrpackIndex::fromCurseForge($cached['index'], $this->driver('curseforge')->filesByIds($ids)), $cached['overrides']];
    }

    /** @return array{index: string, overrides: string[]} */
    private function download(array $mrpack, int $max, bool $curseforge = false): array
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
            $indexName = $curseforge ? 'manifest.json' : 'modrinth.index.json';
            $index = $zip->getFromName($indexName);
            if ($index === false) {
                $zip->close();
                throw new ModpackException("The modpack has no {$indexName}.");
            }
            $dirs = $curseforge
                ? [trim((string) (json_decode($index, true)['overrides'] ?? 'overrides'), '/') . '/']
                : ['overrides/', 'server-overrides/'];

            // Files the overrides folders will place (server-overrides win).
            $overrides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                foreach ($dirs as $prefix) {
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

    /**
     * CurseForge: the pack already names each file's project; one request
     * for the project names, keyed by sha1 like identify().
     */
    private function identifyCurseForge(\ServerSuite\Drivers\CurseForgeDriver $driver, array $files): array
    {
        $cf = array_filter($files, fn ($f) => isset($f['curseforge']));
        try {
            $mods = $driver->modsByIds(array_column(array_column($cf, 'curseforge'), 'project_id'));
        } catch (\Throwable) {
            $mods = [];
        }

        $out = [];
        foreach ($cf as $f) {
            $c = $f['curseforge'];
            $m = $mods[$c['project_id']] ?? null;
            $out[$f['sha1']] = [
                'project_id'     => $c['project_id'],
                'name'           => $m?->name ?? $c['name'],
                'slug'           => $m?->slug ?? $c['project_id'],
                'icon_url'       => $m?->iconUrl,
                'page_url'       => $m?->pageUrl,
                'version_id'     => $c['file_id'],
                'version_number' => $c['name'],
                'dependencies'   => [],
            ];
        }

        return $out;
    }
}
