<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Exceptions\RepositoryException;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * Modrinth repository driver.
 *
 * API base: https://api.modrinth.com/v2
 * Docs:     https://docs.modrinth.com/api/
 *
 * Auth: none required for read operations.
 * Rate limits: returned in X-Ratelimit-* headers; we cache aggressively to stay well under.
 *
 * Key Modrinth concepts:
 *   - "project" = a mod / plugin / modpack listing
 *   - "version" = a specific release of a project, containing one or more files
 *   - Loader names are lowercase: fabric, forge, neoforge, quilt, paper, spigot, purpur, etc.
 *   - Search uses facets: [["categories:fabric"],["versions:1.21.1"],["project_type:mod"]]
 */
class ModrinthDriver extends AbstractDriver
{
    /** Minecraft Java game ID on Modrinth */
    private const GAME_ID = 'minecraft';

    /** Cache TTL in seconds for search/get results */
    private const CACHE_TTL = 300; // 5 minutes
    private const TAG_TTL = 86400; // 24h — the MC version list changes rarely

    public function key(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    public function isAvailable(): bool
    {
        return (bool) config('serversuite.repositories.modrinth', true);
    }

    protected function baseUrl(): string
    {
        return rtrim(config('serversuite.urls.modrinth', 'https://api.modrinth.com/v2'), '/');
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search Modrinth projects.
     *
     * GET /search
     * Facets used: categories (loader), versions (game version), project_type
     *
     * @return ModResult[]
     */
    /**
     * The real mod-loader slugs Modrinth uses, as opposed to content
     * categories (adventure, worldgen, etc.) that also appear in a project's
     * `categories`/`display_categories`. Used to extract a clean loaders list.
     */
    private const MOD_LOADERS = [
        'fabric', 'forge', 'neoforge', 'quilt', 'liteloader', 'modloader', 'rift',
        // plugin platforms (Modrinth lists these as loaders on plugin projects)
        'paper', 'spigot', 'bukkit', 'purpur', 'folia', 'bungeecord', 'waterfall',
        'velocity', 'sponge',
    ];

    /** Loaders that indicate a PLUGIN project on Modrinth rather than a mod. */
    private const PLUGIN_LOADERS = [
        'paper', 'spigot', 'bukkit', 'purpur', 'folia', 'bungeecord', 'waterfall',
        'velocity', 'sponge',
    ];

    public function search(
        string $query,
        ?string $loader = null,
        ?string $gameVersion = null,
        int $limit = 20,
        ?string $sort = null,
        int $offset = 0
    ): array {
        // Project type follows the loader: plugin platforms (paper/spigot/...)
        // search Modrinth's plugins, mod loaders search mods, and no loader
        // searches both. This is what lets e.g. LuckPerms (a plugin) appear.
        if ($loader && in_array(strtolower($loader), self::PLUGIN_LOADERS, true)) {
            $facets = [['project_type:plugin']];
        } elseif ($loader) {
            $facets = [['project_type:mod']];
        } else {
            $facets = [['project_type:mod', 'project_type:plugin']];
        }

        if ($loader) {
            $facets[] = ["categories:{$loader}"];
        }

        if ($gameVersion) {
            $facets[] = ["versions:{$gameVersion}"];
        }

        // Our sort keys -> Modrinth index values. "follows" is Modrinth's
        // likes/hearts metric.
        $index = match ($sort) {
            'downloads' => 'downloads',
            'follows'   => 'follows',
            'newest'    => 'newest',
            'updated'   => 'updated',
            default     => 'relevance',
        };

        $cacheKey = "serversuite.modrinth.search." . md5($query . $loader . $gameVersion . $limit . $index . $offset);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($query, $facets, $limit, $index, $offset) {
            $response = $this->http()->get('/search', [
                'query'  => $query,
                'facets' => json_encode($facets),
                'limit'  => min($limit, 100),
                'offset' => max($offset, 0),
                'index'  => $index,
            ]);

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return collect($response->json('hits', []))
                ->map(fn (array $hit) => $this->hitToModResult($hit))
                ->all();
        });
    }

    // -------------------------------------------------------------------------
    // Modpacks (#23)
    // -------------------------------------------------------------------------

    /**
     * Search modpacks that can run on a server (server_side required or
     * optional; client-only packs are left out).
     *
     * @param string|string[]|null $loader one loader, or any of several
     *
     * @return ModResult[]
     */
    public function searchModpacks(string $query, int $limit = 24, int $offset = 0, ?string $sort = null, string|array|null $loader = null, ?string $gameVersion = null): array
    {
        $facets = [['project_type:modpack'], ['server_side:required', 'server_side:optional']];
        if ($loader) {
            $facets[] = array_map(fn ($l) => "categories:{$l}", (array) $loader);
        }
        if ($gameVersion) {
            $facets[] = ["versions:{$gameVersion}"];
        }
        $index = in_array($sort, ['downloads', 'follows', 'newest', 'updated'], true) ? $sort : 'relevance';
        $key = 'serversuite.modrinth.packs.' . md5($query . '|' . $limit . '|' . $offset . '|' . $index . '|' . implode(',', (array) $loader) . '|' . $gameVersion);

        return Cache::remember($key, self::CACHE_TTL, function () use ($query, $facets, $limit, $offset, $index) {
            $res = $this->http()->get('/search', [
                'query' => $query, 'facets' => json_encode($facets),
                'limit' => min($limit, 100), 'offset' => max($offset, 0), 'index' => $index,
            ]);
            if (! $res->successful()) {
                throw RepositoryException::httpError($this->key(), $res->status(), $res->body());
            }

            return collect($res->json('hits', []))->map(fn (array $hit) => $this->hitToModResult($hit, 'modpack'))->all();
        });
    }

    /**
     * A modpack's versions, newest first, each with its .mrpack file.
     *
     * @return array<int, array{id: string, version_number: string, name: string, game_versions: string[], loaders: string[], channel: string, published_at: ?string, mrpack: ?array{url: string, size: int, sha1: ?string, filename: string}}>
     */
    public function modpackVersions(string $projectId): array
    {
        return Cache::remember('serversuite.modrinth.packversions.' . md5($projectId), self::CACHE_TTL, function () use ($projectId) {
            $res = $this->http()->get('/project/' . rawurlencode($projectId) . '/version');
            if (! $res->successful()) {
                throw RepositoryException::httpError($this->key(), $res->status(), $res->body());
            }

            return collect((array) $res->json())->map(fn (array $v) => $this->packVersion($v))->values()->all();
        });
    }

    /** One modpack version by id, or null. */
    public function modpackVersion(string $versionId): ?array
    {
        $res = $this->http()->get('/version/' . rawurlencode($versionId));

        return $res->successful() && is_array($res->json()) ? ($this->packVersion($res->json()) + ['project_id' => (string) $res->json('project_id')]) : null;
    }

    /**
     * Identify files by SHA-1 in two requests (version_files, then projects).
     *
     * @param string[] $sha1s
     * @return array<string, array{mod: ModResult, version: ModVersion}>  keyed by sha1
     */
    public function identifyFiles(array $sha1s): array
    {
        $sha1s = array_values(array_unique(array_filter($sha1s)));
        if ($sha1s === []) {
            return [];
        }

        $versions = [];
        foreach (array_chunk($sha1s, 500) as $chunk) {
            $res = $this->http()->post('/version_files', ['hashes' => $chunk, 'algorithm' => 'sha1']);
            if ($res->successful()) {
                $versions += (array) $res->json();
            }
        }

        $projects = [];
        $ids = array_values(array_unique(array_filter(array_column($versions, 'project_id'))));
        foreach (array_chunk($ids, 100) as $chunk) {
            $res = $this->http()->get('/projects', ['ids' => json_encode($chunk)]);
            foreach ($res->successful() ? (array) $res->json() : [] as $p) {
                $projects[$p['id']] = $this->projectToModResult($p);
            }
        }

        $out = [];
        foreach ($versions as $sha1 => $v) {
            $mod = $projects[$v['project_id'] ?? ''] ?? null;
            if ($mod !== null) {
                $out[strtolower((string) $sha1)] = ['mod' => $mod, 'version' => $this->versionToModVersion($v)];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $v */
    private function packVersion(array $v): array
    {
        $files = (array) ($v['files'] ?? []);
        $mrpack = collect($files)->first(fn ($f) => ($f['primary'] ?? false) && str_ends_with((string) ($f['filename'] ?? ''), '.mrpack'))
            ?? collect($files)->first(fn ($f) => str_ends_with((string) ($f['filename'] ?? ''), '.mrpack'));

        return [
            'id'             => (string) $v['id'],
            'version_number' => (string) ($v['version_number'] ?? ''),
            'name'           => (string) ($v['name'] ?? ''),
            'game_versions'  => (array) ($v['game_versions'] ?? []),
            'loaders'        => (array) ($v['loaders'] ?? []),
            'channel'        => in_array($v['version_type'] ?? '', ['beta', 'alpha'], true) ? $v['version_type'] : 'release',
            'published_at'   => $v['date_published'] ?? null,
            'mrpack'         => $mrpack ? [
                'url'      => (string) $mrpack['url'],
                'size'     => (int) ($mrpack['size'] ?? 0),
                'sha1'     => isset($mrpack['hashes']['sha1']) ? strtolower((string) $mrpack['hashes']['sha1']) : null,
                'filename' => (string) $mrpack['filename'],
            ] : null,
        ];
    }

    // -------------------------------------------------------------------------
    // Get a single project
    // -------------------------------------------------------------------------

    /**
     * GET /project/{id|slug}
     */
    public function get(string $modId): ?ModResult
    {
        $cacheKey = "serversuite.modrinth.project.{$modId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($modId) {
            $response = $this->http()->get("/project/{$modId}");

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return $this->projectToModResult($response->json());
        });
    }

    // -------------------------------------------------------------------------
    // Versions
    // -------------------------------------------------------------------------

    /**
     * Live Minecraft *release* versions from Modrinth's tag endpoint, in the
     * order Modrinth returns them (newest-first). Lets the UI version filter
     * pick up new Minecraft releases with no code change. Cached for a day;
     * returns [] on any failure so callers can fall back to a static list.
     *
     * GET /tag/game_version
     *
     * @return string[]
     */
    public function gameVersionTags(): array
    {
        return Cache::remember('serversuite.modrinth.tag.game_version', self::TAG_TTL, function () {
            $response = $this->http()->get('/tag/game_version');

            if (! $response->successful()) {
                return [];
            }

            // Release versions only — snapshots/betas would clutter the filter
            // and aren't what mods are normally indexed against.
            return collect($response->json() ?? [])
                ->filter(fn ($t) => ($t['version_type'] ?? null) === 'release')
                ->pluck('version')
                ->filter()
                ->values()
                ->all();
        });
    }

    /**
     * GET /project/{id}/version
     *
     * @return ModVersion[]
     */
    public function versions(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): array {
        $cacheKey = "serversuite.modrinth.versions.{$modId}." . md5((string)$loader . (string)$gameVersion);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($modId, $loader, $gameVersion) {
            $params = [];

            if ($loader) {
                $params['loaders'] = json_encode([$loader]);
            }

            if ($gameVersion) {
                $params['game_versions'] = json_encode([$gameVersion]);
            }

            $response = $this->http()->get("/project/{$modId}/version", $params);

            if ($response->status() === 404) {
                return [];
            }

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return collect($response->json() ?? [])
                ->map(fn (array $v) => $this->versionToModVersion($v))
                ->all();
        });
    }

    /**
     * Returns the first (most recent) compatible version, or null.
     */
    public function latestVersion(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): ?ModVersion {
        $versions = $this->versions($modId, $loader, $gameVersion);

        return $versions[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Mappers — raw API response → our DTOs
    // -------------------------------------------------------------------------

    /**
     * Map a /search hit to ModResult.
     * Search hits have a different shape from /project/{id} responses.
     */
    private function hitToModResult(array $hit, string $kind = 'mod'): ModResult
    {
        return new ModResult(
            source:       $this->key(),
            id:           $hit['project_id'],
            slug:         $hit['slug'],
            name:         $hit['title'],
            description:  $hit['description'] ?? '',
            author:       $hit['author'] ?? '',
            downloads:    (int) ($hit['downloads'] ?? 0),
            iconUrl:      $hit['icon_url'] ?? null,
            pageUrl:      "https://modrinth.com/{$kind}/{$hit['slug']}",
            loaders:      $this->extractLoaders($hit),
            gameVersions: $hit['versions'] ?? [],
            dependencies: [],  // not available in search results; fetched on get()
            follows:      isset($hit['follows']) ? (int) $hit['follows'] : null,
        );
    }

    /**
     * Extract a clean loaders list from a search hit. Modrinth search hits
     * carry loader names inside `categories`/`display_categories` mixed with
     * content tags, and may also expose a dedicated `loaders` array. We prefer
     * the explicit `loaders` field and otherwise filter categories down to the
     * known mod-loader slugs, so the UI never shows "adventure" as a loader.
     *
     * @param array $hit
     * @return string[]
     */
    private function extractLoaders(array $hit): array
    {
        if (! empty($hit['loaders']) && is_array($hit['loaders'])) {
            return array_values($hit['loaders']);
        }

        $cats = $hit['display_categories'] ?? $hit['categories'] ?? [];

        return array_values(array_filter(
            $cats,
            fn ($c) => in_array(strtolower((string) $c), self::MOD_LOADERS, true)
        ));
    }

    /**
     * Map a /project/{id} response to ModResult.
     */
    private function projectToModResult(array $project): ModResult
    {
        return new ModResult(
            source:       $this->key(),
            id:           $project['id'],
            slug:         $project['slug'],
            name:         $project['title'],
            description:  $project['description'] ?? '',
            author:       $project['team'] ?? '',
            downloads:    (int) ($project['downloads'] ?? 0),
            iconUrl:      $project['icon_url'] ?? null,
            pageUrl:      "https://modrinth.com/mod/{$project['slug']}",
            loaders:      $project['loaders'] ?? [],
            gameVersions: $project['game_versions'] ?? [],
            dependencies: [],  // use getdependencies endpoint if needed; resolved in Phase 6
            versions:     [],
            body:         $project['body'] ?? null,
            categories:   $this->contentCategories($project['categories'] ?? []),
            follows:      isset($project['followers']) ? (int) $project['followers'] : null,
            updatedAt:    $project['updated'] ?? null,
            publishedAt:  $project['published'] ?? null,
        );
    }

    /**
     * Content categories only (exclude loader names, which Modrinth also lists
     * under `categories`). Mirrors the loader/category split used in search.
     *
     * @param string[] $categories
     * @return string[]
     */
    private function contentCategories(array $categories): array
    {
        return array_values(array_filter(
            $categories,
            fn ($c) => ! in_array(strtolower((string) $c), self::MOD_LOADERS, true)
        ));
    }

    /**
     * Map a version object to ModVersion.
     * Each version can have multiple files; we pick the primary one.
     */
    public function lookupByHash(string $sha1): ?array
    {
        $resp = $this->http()->get("/version_file/{$sha1}", ['algorithm' => 'sha1']);

        if (! $resp->successful()) {
            return null;   // 404 = unknown file; treat any failure as a miss
        }

        $versionData = $resp->json();
        if (! is_array($versionData) || empty($versionData['project_id'])) {
            return null;
        }

        $mod = $this->get($versionData['project_id']);
        if (! $mod) {
            return null;
        }

        return ['mod' => $mod, 'version' => $this->versionToModVersion($versionData)];
    }

    private function versionToModVersion(array $version): ModVersion
    {
        // Pick the primary file (Modrinth marks one file as primary; fall back to first)
        $files   = $version['files'] ?? [];
        $primary = collect($files)->firstWhere('primary', true) ?? ($files[0] ?? null);

        $channel = match ($version['version_type'] ?? 'release') {
            'beta'  => 'beta',
            'alpha' => 'alpha',
            default => 'release',
        };

        return new ModVersion(
            versionId:     $version['id'],
            versionNumber: $version['version_number'],
            loaders:       $version['loaders'] ?? [],
            gameVersions:  $version['game_versions'] ?? [],
            downloadUrl:   $primary['url'] ?? '',
            filename:      $primary['filename'] ?? "{$version['id']}.jar",
            fileSize:      (int) ($primary['size'] ?? 0),
            changelog:     $version['changelog'] ?? null,
            hashes:        $this->normaliseHashes($primary['hashes'] ?? []),
            channel:       $channel,
            dependencies:  $this->requiredDeps($version['dependencies'] ?? []),
            downloads:     (int) ($version['downloads'] ?? 0),
            publishedAt:   $version['date_published'] ?? null,
        );
    }

    /**
     * Modrinth version dependencies: [{project_id, dependency_type, ...}].
     * We keep only 'required' deps that name a project.
     *
     * @param array<int, array{project_id?: ?string, dependency_type?: string}> $deps
     * @return string[]
     */
    private function requiredDeps(array $deps): array
    {
        $out = [];
        foreach ($deps as $dep) {
            if (($dep['dependency_type'] ?? '') === 'required' && ! empty($dep['project_id'])) {
                $out[] = (string) $dep['project_id'];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Modrinth file hashes come as ['sha1' => '...', 'sha512' => '...'].
     *
     * @param array<string, string> $hashes
     * @return array<string, string>
     */
    private function normaliseHashes(array $hashes): array
    {
        $out = [];
        foreach (['sha1', 'sha512'] as $algo) {
            if (! empty($hashes[$algo])) {
                $out[$algo] = strtolower((string) $hashes[$algo]);
            }
        }
        return $out;
    }
}
