<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * GeyserMC's own downloads (download.geysermc.org) for Geyser and Floodgate
 * (#28): the official builds for every platform, with SHA-256. Not a
 * searchable repository; installs and update checks only.
 */
class GeyserMcDriver extends AbstractDriver
{
    private const PROJECTS = [
        'geyser'    => ['Geyser', 'Lets Bedrock Edition players join a Java server.'],
        'floodgate' => ['Floodgate', 'Lets Bedrock players join without a Java account (works with Geyser).'],
    ];

    /** Server loader -> GeyserMC platform key. */
    private const PLATFORMS = [
        'paper' => 'spigot', 'purpur' => 'spigot', 'spigot' => 'spigot', 'bukkit' => 'spigot',
        'fabric' => 'fabric', 'neoforge' => 'neoforge', 'velocity' => 'velocity',
    ];

    public function key(): string
    {
        return 'geysermc';
    }

    public function label(): string
    {
        return 'GeyserMC';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('serversuite.urls.geysermc', 'https://download.geysermc.org/v2'), '/');
    }

    public function search(string $query, ?string $loader = null, ?string $gameVersion = null, int $limit = 20, ?string $sort = null, int $offset = 0): array
    {
        return [];
    }

    public function lookupByHash(string $sha1): ?array
    {
        return null;
    }

    public function get(string $modId): ?ModResult
    {
        [$name, $description] = self::PROJECTS[$modId] ?? [null, null];
        if ($name === null) {
            return null;
        }

        return new ModResult(
            source: $this->key(), id: $modId, slug: $modId, name: $name, description: $description,
            author: 'GeyserMC', downloads: 0, iconUrl: null, pageUrl: "https://geysermc.org/download?project={$modId}",
            loaders: array_keys(self::PLATFORMS), gameVersions: [], dependencies: [],
        );
    }

    /** The latest build, if it has one for this loader. */
    public function versions(string $modId, ?string $loader = null, ?string $gameVersion = null): array
    {
        if (! isset(self::PROJECTS[$modId])) {
            return [];
        }
        $build = Cache::remember("serversuite.geysermc.{$modId}", 3600, function () use ($modId) {
            $res = $this->http()->withOptions(['allow_redirects' => true])->get("/projects/{$modId}/versions/latest/builds/latest");

            return $res->successful() ? $res->json() : null;
        });
        if (! is_array($build)) {
            return [];
        }

        $platforms = $loader ? [self::PLATFORMS[strtolower($loader)] ?? null] : array_keys($build['downloads'] ?? []);
        $out = [];
        foreach (array_filter($platforms) as $platform) {
            $file = $build['downloads'][$platform] ?? null;
            if ($file === null) {
                continue;
            }
            $out[] = new ModVersion(
                versionId: "{$build['version']}-b{$build['build']}-{$platform}",
                versionNumber: "{$build['version']}-b{$build['build']}",
                loaders: $loader ? [strtolower($loader)] : [$platform],
                gameVersions: [],
                downloadUrl: $this->baseUrl() . "/projects/{$modId}/versions/{$build['version']}/builds/{$build['build']}/downloads/{$platform}",
                filename: (string) $file['name'],
                fileSize: 0,
                changelog: $build['changes'][0]['summary'] ?? null,
                hashes: isset($file['sha256']) ? ['sha256' => (string) $file['sha256']] : [],
            );
        }

        return $out;
    }

    public function latestVersion(string $modId, ?string $loader = null, ?string $gameVersion = null): ?ModVersion
    {
        return $this->versions($modId, $loader, $gameVersion)[0] ?? null;
    }

    public function platformFor(string $loader): ?string
    {
        return self::PLATFORMS[strtolower($loader)] ?? null;
    }
}
