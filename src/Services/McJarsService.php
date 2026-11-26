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
     * Minecraft release versions a loader has builds for, newest first
     * (snapshots and pre-releases left out). Empty when MCJars is off or
     * unreachable; an empty answer is never cached, so the next load retries.
     *
     * @return list<string>
     */
    public function releases(?string $loaderKeyword): array
    {
        $type = $this->typeFor($loaderKeyword);
        if (! config('serversuite.versions.mcjars', true) || $type === null) {
            return [];
        }

        $key = "serversuite.mcjars.releases.{$type}";
        $cached = Cache::get($key);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $res = $this->http()->get(self::API . "/builds/{$type}");
            if (! $res->successful()) {
                Log::warning('[Server Suite] MCJars version list failed', ['type' => $type, 'status' => $res->status()]);

                return [];
            }
            $out = [];
            foreach ((array) $res->json('builds', []) as $version => $info) {
                $version = (string) $version;
                if (strtoupper((string) (((array) $info)['type'] ?? '')) === 'RELEASE' && preg_match('/^\d+(?:\.\d+)+$/', $version)) {
                    $out[] = $version;
                }
            }
            usort($out, fn ($a, $b) => self::tuple($b) <=> self::tuple($a));
            if ($out !== []) {
                Cache::put($key, $out, 3600);
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] MCJars error', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * The loader build to install for a Minecraft version: the newest stable
     * one, else the newest (NeoForge's first builds for a new Minecraft are
     * betas). Null when MCJars has none.
     *
     * @return array{version: string, experimental: bool}|null
     */
    public function newestLoaderBuild(?string $loaderKeyword, string $mcVersion): ?array
    {
        $type = $this->typeFor($loaderKeyword);
        if (! config('serversuite.versions.mcjars', true) || $type === null || ! preg_match('/^[A-Za-z0-9._-]+$/', $mcVersion)) {
            return null;
        }

        $key = "serversuite.mcjars.loader.{$type}.{$mcVersion}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $res = $this->http()->get(self::API . "/builds/{$type}/{$mcVersion}");
            if (! $res->successful()) {
                return null;
            }
            $first = null;
            $stable = null;
            foreach ((array) $res->json('builds', []) as $b) {
                $b = (array) $b;
                $v = (string) ($b['projectVersionId'] ?? $b['name'] ?? '');
                if ($v === '' || ! preg_match('/^[A-Za-z0-9._+-]+$/', $v)) {
                    continue;
                }
                $first ??= ['version' => $v, 'experimental' => (bool) ($b['experimental'] ?? false)];
                if (! ($b['experimental'] ?? false)) {
                    $stable = ['version' => $v, 'experimental' => false];
                    break;
                }
            }
            $pick = $stable ?? $first;
            if ($pick !== null) {
                Cache::put($key, $pick, 1800);
            }

            return $pick;
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] MCJars error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return list<int> */
    private static function tuple(string $v): array
    {
        return array_map('intval', array_pad(explode('.', $v), 4, '0'));
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
