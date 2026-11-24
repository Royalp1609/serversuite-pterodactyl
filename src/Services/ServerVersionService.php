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

    /** The Java version in an image name or label: java21, java_21, java-21, "Java 21" (item 72). */
    public const JAVA_PATTERN = '/java[ _-]?(\d+)/i';

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
     * @return array<int, array{build: int, channel: string, download_url: ?string, time: ?string}>
     */
    public function paperBuilds(string $version): array
    {
        // The version goes into the Fill URL and the cache key, and comes
        // from the request: accept only a release-style version (#122).
        if (! preg_match('/^\d{1,4}(?:\.\d{1,4}){1,3}$/', $version)) {
            return [];
        }

        $key = 'serversuite.versions.paper.builds.' . $version;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        // Only a real answer is cached: the build list, or a 404 for a version
        // Paper doesn't have. A timeout or server error is not "no builds"
        // and would otherwise block switches and auto-upgrades for an hour.
        try {
            $resp = $this->http()->get("/projects/paper/versions/{$version}/builds");
        } catch (\Illuminate\Http\Client\RequestException $e) {
            $resp = $e->response;   // retry() throws on an error status
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] Paper Fill v3 builds unreachable', ['version' => $version, 'error' => $e->getMessage()]);

            return [];
        }

        if ($resp->status() === 404) {
            Cache::put($key, [], self::TTL);

            return [];
        }
        if (! $resp->successful()) {
            Log::warning('[Server Suite] Paper Fill v3 builds unavailable', ['version' => $version, 'status' => $resp->status()]);

            return [];
        }

        $out = [];
        foreach ((array) ($resp->json() ?? []) as $b) {
            if (! is_array($b)) {
                continue;
            }
            // Fill v3 download key for the server jar is 'server:default'.
            $url = $b['downloads']['server:default']['url']
                ?? $b['downloads']['application']['url']   // tolerate older key
                ?? null;

            $out[] = [
                'build'        => (int) ($b['id'] ?? 0),
                'channel'      => (string) ($b['channel'] ?? 'STABLE'),
                'download_url' => $url,
                'time'         => isset($b['time']) ? (string) $b['time'] : null,
            ];
        }

        // Newest build first.
        usort($out, fn ($a, $z) => $z['build'] <=> $a['build']);
        Cache::put($key, $out, self::TTL);

        return $out;
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
     * Pick the Docker image for a target version from the egg's ALLOWED list:
     * the lowest Java inside the band, which is the Java Mojang ships for that
     * version. Mods pin it: Cobblemon for 1.21.1 refuses to load on Java 25
     * ("requires version 21"), seen on the test panel when a newer image was
     * kept. The current image stays only when it is already that Java. Flags
     * when nothing allowed fits (a hard blocker: the egg needs that image).
     *
     * $modsNeed raises the floor: installed plugins built for a newer Java
     * (VeinMiner Enchantment needs 25) stop loading if the switch drops to
     * Mojang's Java, so the lowest allowed Java at or above what they need is
     * picked instead. When none in the band is high enough, the highest in
     * the band is used and the note says those mods won't load.
     *
     * @param  array<string,string> $allowed  label => image (egg docker_images)
     * @return array{resolved:?string,current:?string,current_java:?int,required:array{0:int,1:int},change:bool,allowed:bool,note:string,mods_need:?int}
     */
    public function resolveImage(array $allowed, ?string $currentImage, array $band, ?int $modsNeed = null): array
    {
        [$min, $max] = $band;
        $javaOf = static fn (?string $img): ?int => $img && preg_match(ServerVersionService::JAVA_PATTERN, $img, $m) ? (int) $m[1] : null;

        $currentJava = $javaOf($currentImage);

        // Candidate allowed images whose Java falls inside the band.
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

        ksort($candidates);
        $java = array_key_first($candidates);
        $short = null;   // the installed mods need more Java than this version's images offer
        if ($modsNeed !== null && $modsNeed > $java) {
            $fits = array_filter(array_keys($candidates), fn ($j) => $j >= $modsNeed);
            if ($fits !== []) {
                $java = min($fits);
            } else {
                $java = max(array_keys($candidates));
                $short = $modsNeed;
            }
        }
        $why = $modsNeed !== null && $modsNeed > array_key_first($candidates) && $short === null ? " (installed plugins need Java {$modsNeed})" : '';
        $warn = $short !== null ? " Some installed plugins need Java {$short}, which this version's images don't offer: they won't load until they're updated or removed." : '';

        if ($currentJava === $java) {
            return ['resolved' => $currentImage, 'current' => $currentImage, 'current_java' => $currentJava,
                    'required' => $band, 'change' => false, 'allowed' => true, 'mods_need' => $modsNeed,
                    'note' => "Current image (Java {$currentJava}) is the one for this version{$why} — no image change.{$warn}"];
        }

        return ['resolved' => $candidates[$java], 'current' => $currentImage, 'current_java' => $currentJava,
                'required' => $band, 'change' => true, 'allowed' => true, 'mods_need' => $modsNeed,
                'note' => "Switches the Docker image to Java {$java} for this version{$why}.{$warn}"];
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

        $needs = app(JavaCompatService::class)->installedNeeds($server);
        $image = $this->resolveImage($allowed, $server->image ?? null, $this->requiredJavaBand($targetVersion), $needs[0]['java'] ?? null);

        $warnings = [];
        $blockers = [];

        if (($reinstallBlocker = ReinstallGuard::blocker($server)) !== null) {
            $blockers[] = $reinstallBlocker;
        }

        // The switch pins Paper's MINECRAFT_VERSION + BUILD_NUMBER, so only a
        // Paper server can use it, as the Versions tab shows (#123). Other
        // loaders change version with the loader switch.
        $loader = strtolower((string) ($current['loader'] ?? ''));
        if ($loader !== 'paper') {
            $blockers[] = $loader === ''
                ? "Can't tell which loader this server runs, so its version can't be changed here."
                : 'Version changes here are for Paper servers; this server runs ' . ucfirst($loader) . '. Use the loader switch to pick a version.';
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
        if ($image['allowed'] && ($image['change'] || str_contains($image['note'], "won't load"))) {
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
            // The Java installed plugins need (read from their jars), which raised the image's Java.
            'mods'          => ['checked' => true, 'java' => array_slice($needs, 0, 10)],
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
