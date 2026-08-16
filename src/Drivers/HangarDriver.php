<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * Hangar (PaperMC) repository driver — plugin source for Paper / Waterfall /
 * Velocity servers.
 *
 * API: https://hangar.papermc.io/api/v1  (no auth required for reads)
 *
 * Key differences from the mod drivers:
 *  - Projects are identified by a namespace "author/slug", not a single id.
 *    We pack that into ModResult.id as "author/slug" and split it on lookups.
 *  - Platforms are PAPER / WATERFALL / VELOCITY (uppercase), which map to our
 *    paper / waterfall / velocity loaders. There is no Forge/Fabric here.
 *  - Downloads come from a per-version, per-platform download endpoint.
 */
class HangarDriver extends AbstractDriver
{
    private const CACHE_TTL = 300;

    /** Our loader slugs that Hangar can serve, mapped to Hangar platforms. */
    private const PLATFORM_MAP = [
        'paper'     => 'PAPER',
        'waterfall' => 'WATERFALL',
        'velocity'  => 'VELOCITY',
    ];

    public function key(): string
    {
        return 'hangar';
    }

    public function label(): string
    {
        return 'Hangar';
    }

    public function isAvailable(): bool
    {
        return (bool) config('serversuite.repositories.hangar', true);
    }

    protected function baseUrl(): string
    {
        return config('serversuite.urls.hangar', 'https://hangar.papermc.io/api/v1');
    }

    // -------------------------------------------------------------------------

    public function search(string $query, ?string $loader = null, ?string $gameVersion = null, int $limit = 20, ?string $sort = null, int $offset = 0): array
    {
        $params = [
            'q'      => $query,
            'limit'  => $limit,
            'offset' => max($offset, 0),
        ];

        // Our sort keys -> Hangar sort fields ('-' prefix = descending).
        // "stars" is Hangar's likes metric.
        $hangarSort = match ($sort) {
            'downloads' => '-downloads',
            'follows'   => '-stars',
            'newest'    => '-newest',
            'updated'   => '-updated',
            default     => null,
        };
        if ($hangarSort) {
            $params['sort'] = $hangarSort;
        }

        // Hangar filters by platform (our loader). Only pass a platform it knows.
        $platform = $this->platformFor($loader);
        if ($platform) {
            $params['platform'] = $platform;
        }

        $cacheKey = 'serversuite.hangar.search.' . md5($query . $platform . $gameVersion . $limit . ($hangarSort ?? '') . $offset);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($params) {
            $resp = $this->http()->get('/projects', $params);

            if (! $resp->successful()) {
                return [];
            }

            // Hangar paginates: results under "result"; tolerate a bare array too.
            $results = $resp->json('result') ?? $resp->json() ?? [];
            if (! is_array($results) || isset($results['name'])) {
                $results = [];
            }

            return array_values(array_map(fn ($p) => $this->projectToModResult($p), $results));
        });
    }

    public function lookupByHash(string $sha1): ?array
    {
        return null; // no hash-lookup endpoint wired for this repository yet
    }

    public function get(string $modId): ?ModResult
    {
        [$author, $slug] = $this->splitId($modId);
        if (! $slug) {
            return null;
        }

        $cacheKey = 'serversuite.hangar.project.' . md5($modId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($author, $slug) {
            // Hangar accepts /projects/{slug} and also /projects/{author}/{slug};
            // the slug-only form is the documented canonical lookup.
            $resp = $this->http()->get("/projects/{$slug}");

            if (! $resp->successful()) {
                return null;
            }

            return $this->projectToModResult($resp->json());
        });
    }

    public function versions(string $modId, ?string $loader = null, ?string $gameVersion = null): array
    {
        [$author, $slug] = $this->splitId($modId);
        if (! $slug) {
            return [];
        }

        $cacheKey = 'serversuite.hangar.versions.' . md5($modId . $loader . $gameVersion);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($slug, $loader, $gameVersion, $modId) {
            $resp = $this->http()->get("/projects/{$slug}/versions", ['limit' => 25]);

            if (! $resp->successful()) {
                return [];
            }

            $platform = $this->platformFor($loader);
            $out = [];

            foreach (($resp->json('result') ?? []) as $v) {
                $mv = $this->versionToModVersion($v, $modId, $platform, $gameVersion);
                if ($mv) {
                    $out[] = $mv;
                }
            }

            return $out;
        });
    }

    public function latestVersion(string $modId, ?string $loader = null, ?string $gameVersion = null): ?ModVersion
    {
        return $this->versions($modId, $loader, $gameVersion)[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Mappers
    // -------------------------------------------------------------------------

    private function projectToModResult(array $p): ModResult
    {
        $namespace = $p['namespace'] ?? [];
        $author    = $namespace['owner'] ?? ($p['author'] ?? '');
        $slug      = $namespace['slug'] ?? ($p['slug'] ?? ($p['name'] ?? ''));
        $id        = $author && $slug ? "{$author}/{$slug}" : $slug;

        $stats     = $p['stats'] ?? [];
        $settings  = $p['settings'] ?? [];

        // Hangar exposes supported platforms under supportedPlatforms or via
        // the project's "platforms"/lastUpdated metadata; fall back gracefully.
        $loaders = [];
        $gameVersions = [];
        foreach (array_keys(self::PLATFORM_MAP) as $ourLoader) {
            $hangarPlat = self::PLATFORM_MAP[$ourLoader];
            $supported  = $p['supportedPlatforms'][$hangarPlat] ?? null;
            if ($supported !== null) {
                $loaders[] = $ourLoader;
                if (is_array($supported)) {
                    $gameVersions = array_merge($gameVersions, $supported);
                }
            }
        }
        $gameVersions = array_values(array_unique($gameVersions));

        $iconUrl = $p['avatarUrl'] ?? null;

        return new ModResult(
            source:       'hangar',
            id:           $id,
            slug:         $slug,
            name:         $p['name'] ?? $slug,
            description:  $p['description'] ?? '',
            author:       $author,
            downloads:    (int) ($stats['downloads'] ?? 0),
            iconUrl:      $iconUrl,
            pageUrl:      $author && $slug ? "https://hangar.papermc.io/{$author}/{$slug}" : null,
            loaders:      $loaders,
            gameVersions: $gameVersions,
            dependencies: [],
            versions:     [],
            body:         $p['description'] ?? null,
            categories:   isset($p['category']) ? [(string) $p['category']] : [],
            follows:      isset($stats['stars']) ? (int) $stats['stars'] : null,
            updatedAt:    $p['lastUpdated'] ?? null,
            publishedAt:  $p['createdAt'] ?? null,
        );
    }

    /**
     * Map a Hangar version object to a ModVersion for a given platform.
     * Returns null if the version doesn't support the requested platform
     * (or game version), so the caller's list stays loader-correct.
     */
    private function versionToModVersion(array $v, string $modId, string $platform, ?string $gameVersion): ?ModVersion
    {
        // platformDependencies: { PAPER: ["1.20","1.21"], VELOCITY: [...] }
        $platformDeps = $v['platformDependencies'] ?? [];

        // Which platforms this version supports.
        $supportedPlatforms = array_keys($platformDeps);

        // If a specific platform was requested, require this version to support it.
        if ($platform && ! in_array($platform, $supportedPlatforms, true)) {
            return null;
        }

        // Determine the loaders (our slugs) this version supports.
        $loaders = [];
        foreach (self::PLATFORM_MAP as $ourLoader => $hangarPlat) {
            if (in_array($hangarPlat, $supportedPlatforms, true)) {
                $loaders[] = $ourLoader;
            }
        }

        // Game versions for the requested (or first) platform.
        $platformKey  = $platform ?: ($supportedPlatforms[0] ?? null);
        $gameVersions = $platformKey ? ($platformDeps[$platformKey] ?? []) : [];

        // Optional game-version filter.
        if ($gameVersion && ! in_array($gameVersion, $gameVersions, true)) {
            return null;
        }

        [$author, $slug] = $this->splitId($modId);
        $versionName = $v['name'] ?? '';

        // Download URL: per-version, per-platform. Hangar serves a redirecting
        // download endpoint; external downloads expose a url in downloads[plat].
        $downloadUrl = '';
        $downloads   = $v['downloads'] ?? [];
        if ($platformKey && isset($downloads[$platformKey])) {
            $d = $downloads[$platformKey];
            // Either a hosted file (downloadUrl) or an external link (externalUrl).
            $downloadUrl = $d['downloadUrl']
                ?? $d['externalUrl']
                ?? ($slug && $versionName
                    ? $this->baseUrl() . "/projects/{$slug}/versions/{$versionName}/{$platformKey}/download"
                    : '');
        }

        $channel = strtolower($v['channel']['name'] ?? 'release');
        $fileInfo = $downloads[$platformKey]['fileInfo'] ?? [];

        return new ModVersion(
            versionId:     (string) ($v['name'] ?? ''),
            versionNumber: (string) ($v['name'] ?? ''),
            loaders:       $loaders,
            gameVersions:  $gameVersions,
            downloadUrl:   $downloadUrl,
            filename:      $fileInfo['name'] ?? ($slug . '-' . $versionName . '.jar'),
            fileSize:      (int) ($fileInfo['sizeBytes'] ?? 0),
            changelog:     $v['description'] ?? null,
            hashes:        isset($fileInfo['sha256Hash']) ? ['sha256' => $fileInfo['sha256Hash']] : [],
            channel:       in_array($channel, ['release', 'beta', 'alpha'], true) ? $channel : 'release',
            dependencies:  [],  // Hangar plugin deps differ from mod deps; not resolved in v1
            downloads:     (int) ($v['stats']['totalDownloads'] ?? 0),
            publishedAt:   $v['createdAt'] ?? null,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Split our packed "author/slug" id. Returns [author, slug]; author may be
     * empty if only a slug was stored.
     *
     * @return array{0: string, 1: string}
     */
    private function splitId(string $modId): array
    {
        if (str_contains($modId, '/')) {
            [$author, $slug] = explode('/', $modId, 2);
            return [$author, $slug];
        }
        return ['', $modId];
    }

    /**
     * Map our loader slug to a Hangar platform constant, or null if the loader
     * isn't a Hangar platform (e.g. forge/fabric — not served here).
     */
    private function platformFor(?string $loader): string
    {
        if (! $loader) {
            return '';
        }
        return self::PLATFORM_MAP[strtolower($loader)] ?? '';
    }
}
