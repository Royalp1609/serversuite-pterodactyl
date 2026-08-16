<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The remote egg catalog.
 *
 * Server Suite doesn't ship a hardcoded list of eggs. Instead it reads the
 * community-maintained Pterodactyl egg index published by pelican-eggs and
 * presents it as a nest > egg tree the admin can pick from. This means the
 * catalog covers every game and software category (not just Minecraft) and
 * stays current without us editing config.
 *
 * The index is a single Markdown document:
 *   ## <Category>            → a nest
 *   | [<Egg>](<rawUrl>) | …  → an egg in that nest
 *
 * Parsing one file (≈100 KB, ~300 eggs across 9 nests) means one HTTP request
 * rather than crawling dozens of GitHub repos and hitting the API rate limit.
 *
 * Results are cached on success only — a transient fetch/parse failure must
 * never be cached (it would stick for the whole TTL), so on failure we return
 * the last good cache if we have one, otherwise an empty tree with an error.
 */
class EggCatalogService
{
    private const CACHE_KEY = 'serversuite.egg_index';

    /**
     * The nest > egg tree, cached.
     *
     * @return array{
     *   nests: array<int,array{name:string, eggs:array<int,array{name:string,url:string,description:string}>}>,
     *   source: string,
     *   fetched_at: ?string,
     *   error?: string,
     *   stale?: bool
     * }
     */
    public function index(bool $forceRefresh = false): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (! $forceRefresh && is_array($cached) && ! empty($cached['nests'])) {
            return $cached;
        }

        try {
            $data = $this->fetchAndParse();
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] Egg index fetch failed', ['error' => $e->getMessage()]);
            if (is_array($cached) && ! empty($cached['nests'])) {
                return array_merge($cached, ['stale' => true]);
            }

            return [
                'nests'      => [],
                'source'     => $this->indexUrl(),
                'fetched_at' => null,
                'error'      => $e->getMessage(),
            ];
        }

        // Only cache a non-empty success.
        if (! empty($data['nests'])) {
            Cache::put(self::CACHE_KEY, $data, $this->ttl());
        }

        return $data;
    }

    /** Force a re-fetch and re-cache. Returns the fresh tree. */
    public function refresh(): array
    {
        return $this->index(true);
    }

    /** Find one egg (by nest name + url) in the catalog. */
    public function findEgg(string $nestName, string $url): ?array
    {
        foreach ($this->index()['nests'] as $nest) {
            if (strcasecmp($nest['name'], $nestName) !== 0) {
                continue;
            }
            foreach ($nest['eggs'] as $egg) {
                if ($egg['url'] === $url) {
                    return $egg;
                }
            }
        }

        return null;
    }

    /* ===================================================================== */

    /** Fetch the index Markdown and parse it into the nest > egg tree. */
    private function fetchAndParse(): array
    {
        $url = $this->indexUrl();

        $resp = Http::timeout(20)
            ->withHeaders(['User-Agent' => $this->userAgent()])
            ->get($url);

        if (! $resp->successful()) {
            throw new \RuntimeException('Egg index returned HTTP ' . $resp->status() . '.');
        }

        $nests = $this->parse($resp->body());
        if (empty($nests)) {
            throw new \RuntimeException('Egg index parsed to zero nests (format may have changed).');
        }

        return [
            'nests'      => $nests,
            'source'     => $url,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Parse the index Markdown.
     *   ## Heading            → nest
     *   | [Name](url) | desc | → egg row
     * Header/separator rows don't match the link pattern, so they're ignored.
     *
     * @return array<int,array{name:string, eggs:array<int,array{name:string,url:string,description:string}>}>
     */
    private function parse(string $md): array
    {
        $byNest  = [];
        $order   = [];
        $current = null;

        foreach (preg_split('/\r\n|\r|\n/', $md) as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                $current = trim($m[1]);
                if (! isset($byNest[$current])) {
                    $byNest[$current] = [];
                    $order[]          = $current;
                }
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\|\s*\[(.+?)\]\((https?:\/\/[^)\s]+)\)\s*\|(.*)\|\s*$/u', $line, $m)) {
                $name = trim($m[1]);
                $eurl = trim($m[2]);
                if ($name === '' || $eurl === '') {
                    continue;
                }
                // Only accept egg JSON links (guards against stray links).
                if (! preg_match('/\.json$/i', $eurl)) {
                    continue;
                }
                $desc = trim(preg_replace('/\s+/', ' ', $m[3]));
                if (mb_strlen($desc) > 200) {
                    $desc = mb_substr($desc, 0, 197) . '…';
                }
                $byNest[$current][] = ['name' => $name, 'url' => $eurl, 'description' => $desc];
            }
        }

        $out = [];
        foreach ($order as $name) {
            if (empty($byNest[$name])) {
                continue;
            }
            $out[] = ['name' => $name, 'eggs' => $byNest[$name]];
        }

        return $out;
    }

    private function indexUrl(): string
    {
        return (string) config(
            'serversuite.egg_catalog.index_url',
            'https://raw.githubusercontent.com/pelican-eggs/pelican-eggs.github.io/main/content/pterodactyl.md'
        );
    }

    private function ttl(): int
    {
        return max(300, (int) config('serversuite.egg_catalog.cache_ttl', 21600));
    }

    private function userAgent(): string
    {
        return (string) config('serversuite.versions.user_agent', 'ServerSuite (+egg-catalog)');
    }
}
