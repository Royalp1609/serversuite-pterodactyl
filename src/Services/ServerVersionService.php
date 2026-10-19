<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ServerSuite\Support\ReinstallGuard;

/**
 * Sources the SERVER (jar/loader) versions a server can run — distinct from the
 * Mod Manager's game-version *tags*, which exist only to filter mods.
 *
 * Phase 1 (this file) is READ-ONLY: detect what the server runs now, and list
 * what upstream offers for that loader. The backup-gated switch (Phase 2) lives
 * elsewhere and depends on the server's egg variables, so it is built against a
 * real egg rather than guessed here.
 *
 * Paper uses the Fill v3 API (fill.papermc.io/v3). The legacy api.papermc.io/v2
 * is sunset (disabled 2026-07-01), and v3 REQUIRES a descriptive User-Agent with
 * a contact, so every call sends one from config.
 */
class ServerVersionService
{
    /** Version lists move rarely; cache for an hour. */
    private const TTL = 3600;

    public function __construct(
        private LoaderDetectionService $loaders,
    ) {
    }

    /**
     * What this server runs right now: loader slug + detected Minecraft version.
     * Either may be null when detection is uncertain — the UI handles that.
     */
    public function current(object $server): array
    {
        $detail = $this->loaders->detectDetailed($server);

        return [
            'loader'       => $detail->loader,
            'game_version' => $detail->gameVersion,
            'confidence'   => $detail->confidence ?? null,
        ];
    }

    /**
     * Available Minecraft versions for a loader, newest first.
     *
     * Phase 1 supports Paper; other loaders return an empty list with a
     * 'supported' => false marker so the UI can say "browsing not available yet"
     * rather than implying the server has no versions.
     *
     * @return array{supported: bool, loader: ?string, versions: array<int, string>}
     */
    public function available(?string $loader): array
    {
        $loader = $loader ? strtolower($loader) : null;

        return match ($loader) {
            'paper'  => ['supported' => true,  'loader' => 'paper', 'versions' => $this->paperVersions()],
            default  => ['supported' => false, 'loader' => $loader, 'versions' => []],
        };
    }

    /**
     * Paper's published Minecraft versions (newest first) from Fill v3.
     * Fill returns versions oldest-first under `versions[]`.
     *
     * @return array<int, string>
     */
    private function paperVersions(): array
    {
        $key = 'serversuite.versions.paper';

        // Serve a cached list only if it's a real, non-empty one. Empty/failed
        // fetches are never cached, so a transient upstream hiccup can't stick
        // the "no versions" state for the whole TTL — the next load just retries.
        $cached = Cache::get($key);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $resp = $this->http()->get('/projects/paper');

            if (! $resp->successful()) {
                Log::warning('[Server Suite] Paper Fill v3 version list failed', ['status' => $resp->status()]);

                return [];
            }

            // Fill v3 groups versions as an object: { "1.21": ["1.21.11", ...], ... }
            // (other shapes may be a flat array). Flatten to every version
            // string regardless of nesting, then sort newest-first ourselves
            // so ordering doesn't depend on the API. Year-based (26.x) sorts
            // above legacy (1.x) naturally via the numeric tuple.
            $flat = [];
            $versions = $resp->json('versions', []);   // must be a variable — array_walk_recursive takes it by reference
            array_walk_recursive($versions, function ($v) use (&$flat) {
                if (is_string($v) && $v !== '') {
                    $flat[] = $v;
                }
            });
            $flat = array_values(array_unique($flat));
            // Stable releases only: pure "major.minor[.patch]". Drops
            // -pre / -rc / snapshot builds from the switch list (a
            // show-pre-releases toggle can come later).
            $flat = array_values(array_filter($flat, fn ($v) => (bool) preg_match('/^\d+(?:\.\d+)+$/', $v)));
            usort($flat, fn ($a, $b) => $this->verTuple($b) <=> $this->verTuple($a));

            if ($flat !== []) {
                Cache::put($key, $flat, self::TTL);
            }

            return $flat;
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] Paper Fill v3 unreachable', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Builds for a specific Paper Minecraft version, newest first, with the
     * embedded download URL (Fill v3 supplies it — never construct it yourself).
     * Used by the version detail / Phase 2 switch, not the list view.
     *
     * @return array<int, array{build: int, channel: string, download_url: ?string}>
     */
    public function paperBuilds(string $version): array
    {
        $key = 'serversuite.versions.paper.builds.' . $version;

        return Cache::remember($key, self::TTL, function () use ($version) {
            try {
                $resp = $this->http()->get("/projects/paper/versions/{$version}/builds");

                if (! $resp->successful()) {
                    return [];
                }

                $out = [];
                foreach ($resp->json() ?? [] as $b) {
                    // Fill v3 download key for the server jar is 'server:default'.
                    $url = $b['downloads']['server:default']['url']
                        ?? $b['downloads']['application']['url']   // tolerate older key
                        ?? null;

                    $out[] = [
                        'build'        => (int) ($b['id'] ?? 0),
                        'channel'      => (string) ($b['channel'] ?? 'STABLE'),
                        'download_url' => $url,
                    ];
                }

                // Newest build first.
                usort($out, fn ($a, $z) => $z['build'] <=> $a['build']);

                return $out;
            } catch (\Throwable $e) {
                Log::warning('[Server Suite] Paper Fill v3 builds unreachable', ['version' => $version, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    private function http()
    {
        return Http::baseUrl(rtrim((string) config('serversuite.versions.paper_url'), '/'))
            ->withHeaders(['User-Agent' => (string) config('serversuite.versions.user_agent')])
            ->timeout(15)
            ->retry(2, 200);
    }

    // -------------------------------------------------------------------------
    // Preflight (read-only safety layer for the version switch).
    // -------------------------------------------------------------------------

    /**
     * The Java major-version band a given Paper Minecraft version needs.
     * Minecraft is NOT forward/backward compatible across Java majors, so this
     * is a [min, max] window, not "min and up": e.g. 1.16.5 needs Java 8–11 and
     * will NOT run on 17+. Returns [minJava, maxJava].
     *
     * @return array{0:int,1:int}
     */
    public function requiredJavaBand(string $version): array
    {
        [$major, $minor, $patch] = $this->verTuple($version);

        // Year-based scheme (2026+): version is YY.D.H. The 2026 drops require
        // Java 25 (OpenJDK 25 / Generational ZGC). Future years assume >= 25
        // until a newer floor is known; the egg's allowed list still gates it.
        if ($major >= 26) {
            return [25, 99];
        }

        // Legacy 1.x scheme (the last 1. release was 1.21.11).
        if ($major === 1) {
            return match (true) {
                $minor >= 21                 => [21, 99],
                $minor === 20 && $patch >= 5 => [21, 99],
                $minor === 20                => [17, 21],
                $minor >= 18                 => [17, 21],
                $minor === 17                => [16, 17],
                default                      => [8, 11],   // <= 1.16.5
            };
        }

        return [21, 99]; // unexpected shape — sane modern floor
    }

    /**
     * Pick the Docker image for a target version from the egg's ALLOWED list.
     * Keeps the current image when it already satisfies the band (no needless
     * churn on upgrades); otherwise picks the most modern allowed image still
     * inside the band. Flags when nothing allowed fits (a hard blocker — the
     * egg would need that image added).
     *
     * @param  array<string,string> $allowed  label => image (egg docker_images)
     * @return array{resolved:?string,current:?string,current_java:?int,required:array{0:int,1:int},change:bool,allowed:bool,note:string}
     */
    public function resolveImage(array $allowed, ?string $currentImage, array $band): array
    {
        [$min, $max] = $band;
        $javaOf = static fn (?string $img): ?int => $img && preg_match('/java[_-]?(\d+)/i', $img, $m) ? (int) $m[1] : null;

        $currentJava = $javaOf($currentImage);

        if ($currentJava !== null && $currentJava >= $min && $currentJava <= $max) {
            return ['resolved' => $currentImage, 'current' => $currentImage, 'current_java' => $currentJava,
                    'required' => $band, 'change' => false, 'allowed' => true,
                    'note' => "Current image (Java {$currentJava}) is compatible — no image change."];
        }

        // Candidate allowed images whose Java falls inside the band, most modern first.
        $candidates = [];
        foreach ($allowed as $img) {
            $j = $javaOf($img);
            if ($j !== null && $j >= $min && $j <= $max) {
                $candidates[$j] = $img;
            }
        }

        if (! $candidates) {
            return ['resolved' => null, 'current' => $currentImage, 'current_java' => $currentJava,
                    'required' => $band, 'change' => true, 'allowed' => false,
                    'note' => "This version needs Java {$min}" . ($max < 99 ? "–{$max}" : "+") . ", which isn't in the egg's allowed image list. Add a matching yolks image to the egg, or pick another version."];
        }

        krsort($candidates);
        $java = array_key_first($candidates);

        return ['resolved' => $candidates[$java], 'current' => $currentImage, 'current_java' => $currentJava,
                'required' => $band, 'change' => true, 'allowed' => true,
                'note' => "Switches the Docker image to Java {$java} for this version."];
    }

    /**
     * Read-only preflight for changing to $targetVersion (optionally a specific
     * build). Validates the target exists on v3, resolves the build + download,
     * determines upgrade/downgrade, resolves the Java image against the egg's
     * allowed list, and surfaces warnings/blockers. Performs no changes.
     */
    public function preflight(object $server, string $targetVersion, ?int $targetBuild = null): array
    {
        $current = $this->current($server);
        $direction = $this->direction($current['game_version'] ?? null, $targetVersion);

        $builds = $this->paperBuilds($targetVersion);
        $versionValid = $builds !== [];

        $build = null;
        if ($versionValid) {
            $build = $targetBuild !== null
                ? (collect($builds)->firstWhere('build', $targetBuild) ?? null)
                : ($this->preferredBuild($builds) ?? $builds[0]);
        }

        $allowed = [];
        try {
            foreach ((array) ($server->egg->docker_images ?? []) as $img) {
                $allowed[] = (string) $img;
            }
        } catch (\Throwable $e) {
            // egg not loadable — image check degrades to "unknown" rather than failing
        }

        $image = $this->resolveImage($allowed, $server->image ?? null, $this->requiredJavaBand($targetVersion));

        $warnings = [];
        $blockers = [];

        if (($reinstallBlocker = ReinstallGuard::blocker($server)) !== null) {
            $blockers[] = $reinstallBlocker;
        }

        if (! $versionValid) {
            $blockers[] = "Paper has no published builds for {$targetVersion}.";
        }
        if ($versionValid && $targetBuild !== null && $build === null) {
            $blockers[] = "Build {$targetBuild} doesn't exist for {$targetVersion}.";
        }
        if (! $image['allowed']) {
            $blockers[] = $image['note'];
        }
        if ($direction === 'downgrade') {
            $warnings[] = 'This is a DOWNGRADE. Newer Minecraft worlds often cannot be opened by older versions — your world may fail to load or corrupt. A backup is mandatory and you may need to restore from it.';
        }
        if ($image['change'] && $image['allowed']) {
            $warnings[] = $image['note'];
        }

        return [
            'current'       => $current,
            'target'        => ['version' => $targetVersion, 'build' => $build['build'] ?? null,
                                'channel' => $build['channel'] ?? null, 'download_url' => $build['download_url'] ?? null],
            'direction'     => $direction,
            'version_valid' => $versionValid,
            'image'         => $image,
            'warnings'      => $warnings,
            'blockers'      => $blockers,
            'can_proceed'   => $blockers === [],
            // Mod/plugin compatibility is folded into the switch execution build.
            'mods'          => ['checked' => false],
        ];
    }

    /** Normalise a version to a comparable [major, minor, patch] tuple. */
    /** Normalise a version to a comparable [major, minor, patch] tuple. Only
     *  the leading numeric part is used, so suffixes (-pre7, -rc-2) can't leak
     *  their digits into the version (which was mis-sorting 1.13-pre7 as 1.137). */
    private function verTuple(string $v): array
    {
        preg_match('/^\d+(?:\.\d+)*/', trim($v), $m);

        return array_map('intval', array_pad(explode('.', $m[0] ?? '0'), 3, 0));
    }

    /** upgrade / downgrade / same / unknown by comparing dotted versions. */
    private function direction(?string $from, string $to): string
    {
        if (! $from) {
            return 'unknown';
        }
        $cmp = $this->verTuple($from) <=> $this->verTuple($to);

        return $cmp === 0 ? 'same' : ($cmp < 0 ? 'upgrade' : 'downgrade');
    }

    /** Prefer the newest STABLE build; fall back to the newest of any channel. */
    private function preferredBuild(array $builds): ?array
    {
        foreach ($builds as $b) {
            if (strtoupper((string) ($b['channel'] ?? '')) === 'STABLE') {
                return $b;
            }
        }

        return $builds[0] ?? null;
    }
}
