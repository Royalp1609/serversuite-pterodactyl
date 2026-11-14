<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Exceptions\RepositoryException;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * CurseForge repository driver.
 *
 * API base: https://api.curseforge.com/v1
 * Docs:     https://docs.curseforge.com/rest-api/
 *
 * Auth: requires an API key sent in the `x-api-key` header.
 * Rate limits: 10,000 requests/day on the standard key — cache aggressively.
 *
 * Key CurseForge concepts:
 *   - Minecraft gameId = 432
 *   - classId for "Mc-Mods" = 6, "Bukkit Plugins" = 5
 *   - modLoaderType enum: 1=Forge, 4=Fabric, 5=Quilt, 6=NeoForge
 *   - Search: GET /mods/search?gameId=432&searchFilter=...&modLoaderType=...&gameVersion=...
 *   - Files: GET /mods/{modId}/files  → each file has downloadUrl, gameVersions, etc.
 */
class CurseForgeDriver extends AbstractDriver
{
    /** Minecraft game ID on CurseForge */
    private const GAME_ID = 432;

    /** classId for Minecraft mods */
    private const CLASS_MODS = 6;

    /** classId for Minecraft modpacks (#23) */
    private const CLASS_MODPACKS = 4471;

    /** Cache TTL in seconds — longer than Modrinth because of the daily quota */
    private const CACHE_TTL = 900; // 15 minutes

    /**
     * Map our loader slugs → CurseForge modLoaderType enum values.
     * Loaders not in this map are filtered client-side instead.
     */
    private const LOADER_TYPE = [
        'forge'    => 1,
        'fabric'   => 4,
        'quilt'    => 5,
        'neoforge' => 6,
    ];

    /** Map CurseForge file releaseType int → our channel string */
    private const RELEASE_TYPE = [
        1 => 'release',
        2 => 'beta',
        3 => 'alpha',
    ];

    public function key(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    public function isAvailable(): bool
    {
        return (bool) config('serversuite.repositories.curseforge', true)
            && ! empty(config('serversuite.curseforge_api_key'));
    }

    protected function baseUrl(): string
    {
        return rtrim(config('serversuite.urls.curseforge', 'https://api.curseforge.com/v1'), '/');
    }

    protected function defaultHeaders(): array
    {
        return array_merge(parent::defaultHeaders(), [
            'x-api-key' => (string) config('serversuite.curseforge_api_key', ''),
        ]);
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * GET /mods/search
     *
     * @return ModResult[]
     */
    public function search(
        string $query,
        ?string $loader = null,
        ?string $gameVersion = null,
        int $limit = 20,
        ?string $sort = null,
        int $offset = 0
    ): array {
        if (! $this->isAvailable()) {
            throw RepositoryException::unavailable($this->key());
        }

        $params = [
            'gameId'       => self::GAME_ID,
            'classId'      => self::CLASS_MODS,
            'searchFilter' => $query,
            'pageSize'     => min($limit, 50),
            'sortField'    => 2,      // 2 = Popularity
            'sortOrder'    => 'desc',
        ];

        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }

        if ($loader && isset(self::LOADER_TYPE[$loader])) {
            $params['modLoaderType'] = self::LOADER_TYPE[$loader];
        }

        $cacheKey = "serversuite.curseforge.search." . md5($query . $loader . $gameVersion . $limit);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($params) {
            $response = $this->http()->get('/mods/search', $params);

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return collect($response->json('data', []))
                ->map(fn (array $mod) => $this->modToModResult($mod))
                ->all();
        });
    }

    // -------------------------------------------------------------------------
    // Modpacks (#23 stage 3)
    // -------------------------------------------------------------------------

    /**
     * @param string|string[]|null $loader one loader (CurseForge filters on one), or null
     * @return ModResult[]
     */
    public function searchModpacks(string $query, int $limit = 24, int $offset = 0, ?string $sort = null, string|array|null $loader = null, ?string $gameVersion = null): array
    {
        if (! $this->isAvailable()) {
            throw RepositoryException::unavailable($this->key());
        }
        $params = [
            'gameId' => self::GAME_ID, 'classId' => self::CLASS_MODPACKS, 'searchFilter' => $query,
            'pageSize' => min($limit, 50), 'index' => max(0, $offset), 'sortOrder' => 'desc',
            // 2 popularity, 3 last updated, 6 total downloads, 11 released
            'sortField' => ['downloads' => 6, 'updated' => 3, 'newest' => 11][$sort] ?? 2,
        ];
        if (is_string($loader) && isset(self::LOADER_TYPE[$loader])) {
            $params['modLoaderType'] = self::LOADER_TYPE[$loader];
        }
        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }

        return Cache::remember('serversuite.curseforge.packs.' . md5(json_encode($params)), self::CACHE_TTL, function () use ($params) {
            $res = $this->http()->get('/mods/search', $params);
            if (! $res->successful()) {
                throw RepositoryException::httpError($this->key(), $res->status(), $res->body());
            }

            return collect($res->json('data', []))->map(fn (array $m) => $this->modToModResult($m))->all();
        });
    }

    /**
     * A pack's client-pack files (server packs left out), newest first, in
     * the same shape as ModrinthDriver::modpackVersions ('mrpack' = the
     * pack archive).
     */
    public function modpackVersions(string $projectId): array
    {
        return Cache::remember('serversuite.curseforge.packfiles.' . $projectId, self::CACHE_TTL, function () use ($projectId) {
            $res = $this->http()->get('/mods/' . rawurlencode($projectId) . '/files', ['pageSize' => 50]);
            if (! $res->successful()) {
                throw RepositoryException::httpError($this->key(), $res->status(), $res->body());
            }

            return collect($res->json('data', []))
                ->reject(fn ($f) => ! empty($f['isServerPack']))
                ->sortByDesc(fn ($f) => $f['fileDate'] ?? '')
                ->map(fn ($f) => $this->packFile($f))->values()->all();
        });
    }

    public function modpackVersion(string $fileId, string $projectId): ?array
    {
        $res = $this->http()->get('/mods/' . rawurlencode($projectId) . '/files/' . rawurlencode($fileId));
        $f = $res->successful() ? $res->json('data') : null;

        return is_array($f) && ! empty($f['id']) ? $this->packFile($f) + ['project_id' => (string) ($f['modId'] ?? $projectId)] : null;
    }

    /**
     * Files by id, in one request.
     *
     * @param array<int, int|string> $fileIds
     * @return array<string, array> file id => CurseForge file data
     */
    public function filesByIds(array $fileIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $fileIds))), 500) as $chunk) {
            $res = $this->http()->post('/mods/files', ['fileIds' => $chunk]);
            if (! $res->successful()) {
                throw RepositoryException::httpError($this->key(), $res->status(), $res->body());
            }
            foreach ((array) $res->json('data', []) as $f) {
                $out[(string) $f['id']] = $f;
            }
        }

        return $out;
    }

    /**
     * Projects by id, in one request.
     *
     * @param array<int, int|string> $modIds
     * @return array<string, ModResult>
     */
    public function modsByIds(array $modIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $modIds))), 500) as $chunk) {
            $res = $this->http()->post('/mods', ['modIds' => $chunk]);
            foreach ($res->successful() ? (array) $res->json('data', []) : [] as $m) {
                $out[(string) $m['id']] = $this->modToModResult($m);
            }
        }

        return $out;
    }

    /** SHA-1 of a CurseForge file's hashes list, or null. */
    public static function sha1Of(array $file): ?string
    {
        foreach ((array) ($file['hashes'] ?? []) as $h) {
            if ((int) ($h['algo'] ?? 0) === 1 && ! empty($h['value'])) {
                return strtolower((string) $h['value']);
            }
        }

        return null;
    }

    private function packFile(array $f): array
    {
        $loaders = $games = [];
        $loaderNames = array_map('strtolower', array_keys(self::LOADER_TYPE));
        foreach ((array) ($f['gameVersions'] ?? []) as $v) {
            in_array(strtolower($v), $loaderNames, true) ? $loaders[] = strtolower($v) : $games[] = $v;
        }

        return [
            'id'             => (string) $f['id'],
            'version_number' => (string) ($f['displayName'] ?? $f['fileName'] ?? $f['id']),
            'name'           => (string) ($f['displayName'] ?? ''),
            'game_versions'  => $games,
            'loaders'        => $loaders,
            'channel'        => self::RELEASE_TYPE[$f['releaseType'] ?? 1] ?? 'release',
            'published_at'   => $f['fileDate'] ?? null,
            'server_pack_id' => isset($f['serverPackFileId']) ? (string) $f['serverPackFileId'] : null,
            'mrpack'         => empty($f['downloadUrl']) ? null : [
                'url' => (string) $f['downloadUrl'], 'size' => (int) ($f['fileLength'] ?? 0),
                'sha1' => self::sha1Of($f), 'filename' => (string) ($f['fileName'] ?? 'pack.zip'),
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Get a single mod
    // -------------------------------------------------------------------------

    /**
     * GET /mods/{modId}
     */
    public function lookupByHash(string $sha1): ?array
    {
        return null; // no hash-lookup endpoint wired for this repository yet
    }

    public function get(string $modId): ?ModResult
    {
        if (! $this->isAvailable()) {
            throw RepositoryException::unavailable($this->key());
        }

        $cacheKey = "serversuite.curseforge.mod.{$modId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($modId) {
            $response = $this->http()->get("/mods/{$modId}");

            if ($response->status() === 404) {
                return null;
            }

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return $this->modToModResult($response->json('data', []));
        });
    }

    // -------------------------------------------------------------------------
    // Versions (CurseForge calls them "files")
    // -------------------------------------------------------------------------

    /**
     * GET /mods/{modId}/files
     *
     * @return ModVersion[]
     */
    public function versions(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): array {
        if (! $this->isAvailable()) {
            throw RepositoryException::unavailable($this->key());
        }

        $params = ['pageSize' => 50];

        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }

        if ($loader && isset(self::LOADER_TYPE[$loader])) {
            $params['modLoaderType'] = self::LOADER_TYPE[$loader];
        }

        $cacheKey = "serversuite.curseforge.files.{$modId}." . md5((string)$loader . (string)$gameVersion);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($modId, $params, $loader) {
            $response = $this->http()->get("/mods/{$modId}/files", $params);

            if ($response->status() === 404) {
                return [];
            }

            if (! $response->successful()) {
                throw RepositoryException::httpError($this->key(), $response->status(), $response->body());
            }

            return collect($response->json('data', []))
                ->map(fn (array $file) => $this->fileToModVersion($file))
                // CurseForge doesn't always filter by loader server-side; double-check
                ->filter(function (ModVersion $v) use ($loader) {
                    if (! $loader) {
                        return true;
                    }
                    // Empty loader list = loader-agnostic (e.g. resource packs); keep it
                    return empty($v->loaders) || in_array($loader, $v->loaders, true);
                })
                ->values()
                ->all();
        });
    }

    public function latestVersion(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): ?ModVersion {
        // Files come newest-first; the newest release file, not a beta or alpha (#126).
        return self::newestStable($this->versions($modId, $loader, $gameVersion));
    }

    // -------------------------------------------------------------------------
    // Mappers
    // -------------------------------------------------------------------------

    private function modToModResult(array $mod): ModResult
    {
        // Authors is an array of {id,name,url}
        $author = collect($mod['authors'] ?? [])->pluck('name')->implode(', ');

        // logo is {url,...} or null
        $iconUrl = $mod['logo']['url'] ?? null;

        // links.websiteUrl is the mod page
        $pageUrl = $mod['links']['websiteUrl'] ?? null;

        // Derive loaders + game versions from latestFilesIndexes when present
        $loaders      = [];
        $gameVersions = [];
        foreach ($mod['latestFilesIndexes'] ?? [] as $idx) {
            if (! empty($idx['gameVersion'])) {
                $gameVersions[] = $idx['gameVersion'];
            }
            if (isset($idx['modLoader'])) {
                $slug = array_search($idx['modLoader'], self::LOADER_TYPE, true);
                if ($slug !== false) {
                    $loaders[] = $slug;
                }
            }
        }

        return new ModResult(
            source:       $this->key(),
            id:           (string) $mod['id'],
            slug:         $mod['slug'] ?? (string) $mod['id'],
            name:         $mod['name'] ?? '',
            description:  $mod['summary'] ?? '',
            author:       $author,
            downloads:    (int) ($mod['downloadCount'] ?? 0),
            iconUrl:      $iconUrl,
            pageUrl:      $pageUrl,
            loaders:      array_values(array_unique($loaders)),
            gameVersions: array_values(array_unique($gameVersions)),
            dependencies: [],  // resolved from file data in Phase 6
        );
    }

    private function fileToModVersion(array $file): ModVersion
    {
        // gameVersions on a file mixes loader names and MC versions; split them
        $rawVersions = $file['gameVersions'] ?? [];
        $loaderNames = array_map('strtolower', array_keys(self::LOADER_TYPE));

        $loaders      = [];
        $gameVersions = [];
        foreach ($rawVersions as $v) {
            if (in_array(strtolower($v), $loaderNames, true)) {
                $loaders[] = strtolower($v);
            } else {
                $gameVersions[] = $v;
            }
        }

        // Dependencies: relationType 3 = RequiredDependency
        $dependencies = collect($file['dependencies'] ?? [])
            ->where('relationType', 3)
            ->pluck('modId')
            ->map(fn ($id) => (string) $id)
            ->all();

        $channel = self::RELEASE_TYPE[$file['releaseType'] ?? 1] ?? 'release';

        return new ModVersion(
            versionId:     (string) $file['id'],
            versionNumber: $file['displayName'] ?? ($file['fileName'] ?? (string) $file['id']),
            loaders:       $loaders,
            gameVersions:  $gameVersions,
            downloadUrl:   $file['downloadUrl'] ?? '',
            filename:      $file['fileName'] ?? ((string) $file['id'] . '.jar'),
            fileSize:      (int) ($file['fileLength'] ?? 0),
            changelog:     null,  // separate endpoint: /mods/{id}/files/{fileId}/changelog
            hashes:        $this->normaliseHashes($file['hashes'] ?? []),
            channel:       $channel,
            dependencies:  $dependencies,
        );
    }

    /**
     * CurseForge file hashes come as [['value' => '...', 'algo' => 1|2]],
     * where algo 1 = SHA1 and algo 2 = MD5.
     *
     * @param array<int, array{value?: string, algo?: int}> $hashes
     * @return array<string, string>
     */
    private function normaliseHashes(array $hashes): array
    {
        $map = [1 => 'sha1', 2 => 'md5'];
        $out = [];

        foreach ($hashes as $h) {
            $algo = $map[$h['algo'] ?? 0] ?? null;
            if ($algo && ! empty($h['value'])) {
                $out[$algo] = strtolower((string) $h['value']);
            }
        }

        return $out;
    }
}
