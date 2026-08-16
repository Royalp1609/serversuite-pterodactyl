<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Support\Data\LoaderDetectionResult;

/**
 * Detects the mod loader type (and, where possible, the Minecraft version)
 * for a given server by inspecting multiple signals on its egg.
 *
 * Signals, highest confidence first:
 *   1. egg UUID mapped in config            (confidence 100) — we recognise the egg
 *   2. egg name keyword match               (confidence  85)
 *   3. docker image keyword match           (confidence  70)
 *   4. startup command keyword match        (confidence  55)
 *   5. install-script keyword match         (confidence  45)
 *
 * If nothing matches, detection returns "unknown" rather than guessing. The
 * caller decides whether to prompt the user. We never silently default to a
 * loader, because installing wrong-loader mods is destructive.
 *
 * Loader keyword order is significant and comes from config: more specific
 * loaders (neoforge) are tested before loaders whose names they contain
 * (forge), so "neoforge" never mis-matches as "forge".
 */
class LoaderDetectionService
{
    public function __construct(
        protected DaemonFileClient $files,
    ) {}

    private const CONFIDENCE_EGG_UUID       = 100;
    private const CONFIDENCE_EGG_NAME        = 85;
    private const CONFIDENCE_DOCKER_IMAGE    = 70;
    private const CONFIDENCE_STARTUP         = 55;
    private const CONFIDENCE_INSTALL_SCRIPT  = 45;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Detect the loader for a server and return just the slug (or the stored
     * value). Kept for backwards compatibility with callers that only need a
     * string. Returns null when detection is uncertain and nothing is stored.
     */
    public function detect(object $server): ?string
    {
        $settings = ModManagerSetting::forServer($server->uuid);

        // A locked manual override always wins.
        if ($settings->loader_locked && $settings->loader) {
            return $settings->loader;
        }

        // If we previously stored a confident value, reuse it.
        if ($settings->loader) {
            return $settings->loader;
        }

        $result = $this->detectDetailed($server);

        // Only persist when we're confident enough to act without confirmation.
        if ($result->isConfident()) {
            $settings->update([
                'loader'       => $result->loader,
                'game_version' => $result->gameVersion ?? $settings->game_version,
            ]);

            return $result->loader;
        }

        // Uncertain: store nothing, return the best guess (may be null) so the
        // caller / UI can ask the user to confirm or override.
        return $result->loader;
    }

    /**
     * Detect the loader and return the full result (loader, confidence, signal).
     * This is what the UI should call so it can show confidence and prompt when
     * detection is weak.
     */
    public function detectDetailed(object $server): LoaderDetectionResult
    {
        $egg = $this->egg($server);

        // --- Signal 1: egg UUID mapped in config -----------------------------
        $uuid = $egg->uuid ?? null;
        $uuidMap = (array) config('serversuite.detection.egg_uuids', []);
        if ($uuid && isset($uuidMap[$uuid])) {
            return new LoaderDetectionResult(
                loader: $uuidMap[$uuid],
                confidence: self::CONFIDENCE_EGG_UUID,
                signal: 'egg_uuid',
                gameVersion: $this->detectGameVersion($server, $egg),
            );
        }

        // Gather text from progressively weaker signals.
        $signals = [
            'egg_name'       => [self::CONFIDENCE_EGG_NAME,       (string) ($egg->name ?? '')],
            'docker_image'   => [self::CONFIDENCE_DOCKER_IMAGE,   $this->dockerImagesText($egg) . ' ' . (string) ($server->image ?? '')],
            'startup'        => [self::CONFIDENCE_STARTUP,        (string) ($server->startup ?? $egg->startup ?? '')],
            'install_script' => [self::CONFIDENCE_INSTALL_SCRIPT, (string) ($egg->script_install ?? '')],
        ];

        foreach ($signals as $signalName => [$confidence, $haystack]) {
            $loader = $this->matchLoader($haystack);
            if ($loader !== null) {
                return new LoaderDetectionResult(
                    loader: $loader,
                    confidence: $confidence,
                    signal: $signalName,
                    gameVersion: $this->detectGameVersion($server, $egg),
                );
            }
        }

        return LoaderDetectionResult::unknown();
    }

    /**
     * Manually override the loader for a server (locks it against auto-detection).
     */
    public function override(string $serverUuid, string $loader, ?string $gameVersion = null): ModManagerSetting
    {
        $settings = ModManagerSetting::forServer($serverUuid);

        $settings->update([
            'loader'        => $loader,
            'loader_locked' => true,
            'game_version'  => $gameVersion ?? $settings->game_version,
        ]);

        return $settings;
    }

    /**
     * Clear a manual override, allowing auto-detection to resume.
     */
    public function unlock(string $serverUuid): ModManagerSetting
    {
        $settings = ModManagerSetting::forServer($serverUuid);
        $settings->update(['loader_locked' => false]);

        return $settings;
    }

    // -------------------------------------------------------------------------
    // Matching
    // -------------------------------------------------------------------------

    /**
     * Match a loader against a haystack of text using config keyword lists.
     * Keyword order from config is preserved, so specific loaders win over the
     * loaders whose name they contain (neoforge before forge).
     *
     * Matching is word-boundary aware so "forge" does not match inside an
     * unrelated word, but it still catches "forge-1.21.1" style tokens.
     */
    protected function matchLoader(string $haystack): ?string
    {
        if ($haystack === '') {
            return null;
        }

        $haystack = strtolower($haystack);

        foreach ((array) config('serversuite.detection.keywords', []) as $loader => $keywords) {
            foreach ((array) $keywords as $keyword) {
                $keyword = strtolower($keyword);

                // \b won't fire next to hyphens/dots the way we want, so we
                // accept the keyword when bounded by start/end or a non-letter.
                $pattern = '/(?<![a-z])' . preg_quote($keyword, '/') . '(?![a-z])/';

                if (preg_match($pattern, $haystack)) {
                    return $loader;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Safely resolve the egg model/relation. Returns a stdClass-ish object or
     * an empty object so property access never throws.
     */
    protected function egg(object $server): object
    {
        // $server->egg may be a relation, already-loaded model, or absent.
        $egg = $server->egg ?? null;

        return is_object($egg) ? $egg : (object) [];
    }

    /**
     * Flatten the egg's docker_images (JSON map or array) into a searchable string.
     */
    protected function dockerImagesText(object $egg): string
    {
        $images = $egg->docker_images ?? null;

        if (is_string($images)) {
            // Could be a JSON string; decode if so, else use as-is.
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [$images];
        }

        if (! is_array($images)) {
            return '';
        }

        // Include both keys ("Java 21") and values (image refs).
        return implode(' ', array_merge(array_keys($images), array_values($images)));
    }

    /**
     * Best-effort Minecraft version detection.
     *
     * IMPORTANT: this is deliberately conservative. A wrongly-detected version
     * is worse than none — it filters all search results to an incompatible
     * version. So we ONLY accept a concrete x.y[.z] value found in a dedicated
     * Minecraft-version egg variable. We never scrape it from the startup
     * command or Forge/library version strings (e.g. "forge-26.1.2-64.0.8"
     * contains "1.2" but that is NOT a Minecraft version).
     *
     * Placeholder values like "latest", "recommended", or "" resolve to null
     * (= "any version"), because the concrete version isn't knowable from the
     * panel config alone. The user can set it explicitly in the UI.
     */
    protected function detectGameVersion(object $server, object $egg): ?string
    {
        // Names commonly used by Minecraft eggs for the game version.
        $versionVarNames = ['MC_VERSION', 'MINECRAFT_VERSION', 'GAME_VERSION', 'VERSION'];

        $value = $this->serverVariable($server, $versionVarNames);

        if ($value === null) {
            return null;
        }

        $value = trim($value);

        // Reject placeholders and anything that isn't a clean version number.
        // Accept strict Minecraft-style versions: 1.16, 1.21.1, 26.1.2, etc.
        if (preg_match('/^\d{1,2}\.\d{1,2}(?:\.\d{1,2})?$/', $value)) {
            return $value;
        }

        // "latest", "recommended", "", "snapshot", etc. -> the variable can't
        // tell us. Fall through to sourcing the version from the server's own
        // files, which reflect what is ACTUALLY running.
        return $this->sourceGameVersionFromFiles($server);
    }

    /**
     * Source the running Minecraft version from the server's files via Wings.
     *
     * Two pieces of evidence, strongest first:
     *  1. version_history.json — Paper-family servers (Paper/Purpur/Pufferfish)
     *     write the exact version, e.g. "git-Paper-196 (MC: 1.21.4)".
     *  2. logs/latest.log — virtually every server type (vanilla, Forge,
     *     NeoForge, Fabric, Paper) logs "Starting minecraft server version X"
     *     on boot.
     *
     * Results are cached for 10 minutes per server; "unknown" is cached too
     * (as a sentinel) so an unbootable/fresh server doesn't trigger two Wings
     * reads on every page load. Failures of any kind simply mean "unknown" —
     * this path must never break the caller.
     */
    protected function sourceGameVersionFromFiles(object $server): ?string
    {
        $cached = Cache::remember(
            "serversuite.sourced_gv.{$server->uuid}",
            600,
            function () use ($server) {
                $v = '(\d{1,2}\.\d{1,2}(?:\.\d{1,2})?)';

                // 1. Paper family: version_history.json (tiny, exact)
                $json = $this->files->readFile($server->uuid, '/version_history.json', 16384);
                if ($json !== null && preg_match("/MC:\s*{$v}/", $json, $m)) {
                    return $m[1];
                }

                // 2. Server-jar filenames in the root advertise the version for
                // most loaders (forge-26.1.2-..., fabric-server-mc.1.21.4-...,
                // paper-1.21.4-..., minecraft_server.1.21.4.jar). Listings are
                // cheap and immune to file-size limits.
                try {
                    $rootEntries = $this->files->listDirectory($server->uuid, '/');
                } catch (\Throwable $e) {
                    $rootEntries = [];
                }
                $patterns = [
                    "/^forge-{$v}[-.]/i",
                    "/^fabric-server-mc\.?{$v}/i",
                    "/^(?:paper|purpur|pufferfish|folia|spigot)-{$v}[-.]/i",
                    "/^minecraft_server[._-]?{$v}\.jar\$/i",
                ];
                foreach ($rootEntries as $e) {
                    $name = $e['name'] ?? '';
                    foreach ($patterns as $p) {
                        if (preg_match($p, $name, $m)) {
                            return $m[1];
                        }
                    }
                }

                // 3. Forge keeps its MC version in the libraries tree:
                // libraries/net/minecraftforge/forge/{mc}-{forge}/
                try {
                    $forgeDirs = $this->files->listDirectory($server->uuid, '/libraries/net/minecraftforge/forge');
                    foreach ($forgeDirs as $e) {
                        if (preg_match("/^{$v}-/", $e['name'] ?? '', $m)) {
                            return $m[1];
                        }
                    }
                } catch (\Throwable $e) {
                    // not a Forge server / dir absent — fine
                }

                // 4. Last resort: the boot line in the latest log. Wings REFUSES
                // (not truncates) over-cap reads, so this only works while the
                // log is still reasonably small — hence it's last.
                $log = $this->files->readFile($server->uuid, '/logs/latest.log', 1048576);
                if ($log !== null && preg_match("/[Ss]tarting minecraft server version\s+{$v}/", $log, $m)) {
                    return $m[1];
                }

                return 'unknown'; // sentinel: cached, mapped to null below
            }
        );

        return $cached === 'unknown' ? null : $cached;
    }

    /**
     * Read the first matching server variable value by env name.
     *
     * Handles the Pterodactyl/Pelican shape where $server->variables is a
     * collection of objects with env_variable + server_value. Falls back to
     * the egg's default value if the server hasn't overridden it.
     *
     * @param string[] $names  Candidate env-variable names (case-insensitive).
     */
    protected function serverVariable(object $server, array $names): ?string
    {
        $wanted = array_map('strtoupper', $names);

        $vars = $server->variables ?? null;

        if (! is_iterable($vars)) {
            return null;
        }

        foreach ($vars as $var) {
            if (! is_object($var)) {
                continue;
            }

            $envName = strtoupper((string) ($var->env_variable ?? ''));

            if (in_array($envName, $wanted, true)) {
                // server_value is the user's value; default_value is the egg default.
                $value = $var->server_value
                    ?? $var->variable_value
                    ?? $var->default_value
                    ?? null;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
