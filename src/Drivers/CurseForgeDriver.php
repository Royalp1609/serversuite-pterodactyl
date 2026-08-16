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
        $versions = $this->versions($modId, $loader, $gameVersion);

        // CurseForge returns files newest-first by default
        return $versions[0] ?? null;
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
