<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MCJars (mcjars.app) — free, open-source, no key required. Used to resolve
 * DIRECT jar download URLs so the egg's DL_PATH can bypass broken install
 * scripts (the Folia sunset-v2 case). Attribution: "Powered by MCJars".
 */
class McJarsService
{
    private const API = 'https://mcjars.app/api/v2';

    /** loader keyword -> MCJars type */
    private const TYPES = [
        'paper' => 'PAPER', 'folia' => 'FOLIA', 'purpur' => 'PURPUR',
        'velocity' => 'VELOCITY', 'waterfall' => 'WATERFALL',
        'fabric' => 'FABRIC', 'forge' => 'FORGE', 'neoforge' => 'NEOFORGE',
        'quilt' => 'QUILT', 'spigot' => 'SPIGOT', 'sponge' => 'SPONGE',
    ];

    protected function http()
    {
        return Http::acceptJson()->timeout(10)->connectTimeout(5)
            ->withHeaders(['User-Agent' => 'ServerSuite/1.0 (Pterodactyl mod; powered by MCJars)']);
    }

    public function typeFor(?string $loaderKeyword): ?string
    {
        return self::TYPES[strtolower((string) $loaderKeyword)] ?? null;
    }

    /**
     * Resolve the newest build's direct jar URL for a type+version.
     * MCJars builds expose the download either as jarUrl or inside
     * installation steps — probe both shapes defensively.
     *
     * @return array{url:string, file:string}|null
     */
    public function resolveJarUrl(string $type, string $version): ?array
    {
        $type = strtoupper($type);
        if (! in_array($type, self::TYPES, true) || ! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
            return null;
        }

        $key = "serversuite.mcjars.{$type}.{$version}";

        return Cache::remember($key, 1800, function () use ($type, $version) {
            try {
                $res = $this->http()->get(self::API . "/builds/{$type}/{$version}");
                if (! $res->successful()) {
                    Log::warning('[Server Suite] MCJars lookup failed', ['type' => $type, 'version' => $version, 'status' => $res->status()]);

                    return null;
                }

                $builds = $res->json('builds') ?? $res->json('data') ?? [];
                if (! is_array($builds)) {
                    $builds = [];
                }
                // Some records list the build object directly.
                if ($builds !== [] && array_is_list($builds) === false && isset($builds['buildNumber'])) {
                    $builds = [$builds];
                }

                // MCJars backfills jarUrl lazily from upstream (fill-data),
                // so the newest build can momentarily carry jarUrl=null —
                // scan ALL builds for the first usable URL, newest first.
                foreach ($builds as $b) {
                    $b = (array) $b;
                    foreach (['jarUrl', 'jar_url', 'url'] as $k) {
                        if (! empty($b[$k]) && str_contains((string) $b[$k], 'http')) {
                            return ['url' => (string) $b[$k], 'file' => 'server.jar'];
                        }
                    }
                    foreach ((array) ($b['installation'] ?? []) as $group) {
                        foreach ((array) $group as $step) {
                            $step = (array) $step;
                            if (($step['type'] ?? '') === 'download' && ! empty($step['url'])
                                && str_ends_with(strtolower((string) ($step['file'] ?? '')), '.jar')) {
                                return ['url' => (string) $step['url'], 'file' => (string) $step['file']];
                            }
                        }
                    }
                }

                $latest = is_array($builds) ? (array) (reset($builds) ?: []) : [];
                Log::warning('[Server Suite] MCJars response shape unrecognised', ['keys' => array_keys($latest)]);

                return null;
            } catch (\Throwable $e) {
                Log::warning('[Server Suite] MCJars error', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * The direct jar to pin into the egg's DL_PATH for a switch (#22), or
     * null: unknown loader or version, an MCJars miss, or an installer-based
     * loader (Forge, NeoForge, Quilt), which doesn't run from one jar.
     *
     * @return array{url: string, file: string}|null
     */
    public function pinFor(?string $loaderKeyword, ?string $version): ?array
    {
        if (! config('serversuite.versions.mcjars', true) || ! $loaderKeyword || ! $version
            || in_array(strtolower($loaderKeyword), ['forge', 'neoforge', 'quilt'], true)) {
            return null;
        }
        try {
            $type = $this->typeFor($loaderKeyword);

            return $type ? $this->resolveJarUrl($type, $version) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** The loader an egg is for, from its name ("Paper", "Purpur 1.21" ...). */
    public static function keywordForEgg(string $eggName): ?string
    {
        $name = strtolower($eggName);
        foreach (['folia', 'purpur', 'paper', 'neoforge', 'forge', 'fabric', 'quilt', 'spigot', 'bukkit', 'velocity', 'waterfall', 'sponge'] as $kw) {
            if (str_contains($name, $kw)) {
                return $kw;
            }
        }

        return null;
    }

}
