<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Support\GameGuard;
use ServerSuite\Support\ReinstallGuard;

/**
 * Phase 3 — loader switching (Paper ↔ Fabric ↔ Forge ↔ Vanilla).
 *
 * Unlike a version switch (same egg, two variables changed), a loader switch
 * SWAPS THE EGG. Each egg names things differently, so we can't copy variables
 * by name: we seed the target egg's variables from their defaults and carry the
 * one value that maps semantically — the Minecraft version — into whatever
 * variable the target egg uses for it (MINECRAFT_VERSION, MC_VERSION, …). The
 * runtime image is chosen from the TARGET egg's own image list (registries and
 * available Java bands differ between eggs).
 */
class LoaderSwitchService
{
    /** Env-var names, across eggs, that hold the Minecraft version itself
     *  (deliberately excludes loader/build vars like FABRIC_VERSION, BUILD_NUMBER). */
    private const VERSION_VARS = ['MINECRAFT_VERSION', 'MC_VERSION', 'VERSION', 'GAME_VERSION'];

    private const EGG_MODEL = \Pterodactyl\Models\Egg::class;

    public function __construct(
        private ServerVersionService $versions,
        private PanelServerOps $ops,
        private FileDeliveryService $files,
        private ManifestService $manifest,
    ) {
    }

    /**
     * Seed the target egg's environment: every variable at its default, with the
     * Minecraft version carried into whichever variable holds it.
     *
     * @return array<string,string>
     */
    public function buildEnvironment(object $targetEgg, string $mcVersion): array
    {
        $env = [];
        $versionVar = null;

        foreach ($targetEgg->variables as $v) {
            $env[$v->env_variable] = (string) ($v->default_value ?? '');
            if ($versionVar === null && in_array(strtoupper($v->env_variable), self::VERSION_VARS, true)) {
                $versionVar = $v->env_variable;
            }
        }

        if ($versionVar !== null && $mcVersion !== '') {
            $env[$versionVar] = $mcVersion;
        }

        return $env;
    }

    /**
     * Pick an image from the target egg's list for the version's Java band.
     * The lowest in-band Java wins: the one Mojang ships for that version,
     * which mods pin (Cobblemon for 1.21.1 refuses Java 25). Falls back to the
     * first listed image if nothing parses.
     */
    public function resolveEggImage(object $targetEgg, string $mcVersion): ?string
    {
        [$min, $max] = $this->versions->requiredJavaBand($mcVersion);

        $best = null;
        $bestJava = PHP_INT_MAX;
        foreach ((array) $targetEgg->docker_images as $label => $ref) {
            if (preg_match(ServerVersionService::JAVA_PATTERN, $ref . ' ' . $label, $m)) {
                $j = (int) $m[1];
                if ($j >= $min && $j <= $max && $j < $bestJava) {
                    $bestJava = $j;
                    $best = $ref;
                }
            }
        }

        if ($best !== null) {
            return $best;
        }

        $vals = array_values((array) $targetEgg->docker_images);

        return $vals[0] ?? null;
    }

    /**
     * Switch a server to a different loader egg. A loader change is ALWAYS a
     * clean-slate reset: the old loader's plugins/mods/configs are incompatible
     * with the new one and will stop it booting, so every switch wipes all files
     * (except eula.txt) before reinstalling. This is intended for a single-server
     * operator changing loader/version — start fresh, no half-migrated state.
     *
     * Files can only be wiped while stopped, so this is a small state machine:
     * if the server is still running it fires a stop and returns {status:
     * stopping}; the caller polls status and calls again once offline, at which
     * point wipe + egg-swap + reinstall runs.
     *
     * @param string|null $mcVersion  target MC version, or null = keep current
     * @param array<string, string> $envOverrides  extra egg variables (e.g. a modpack's
     *        exact loader version); only variables the egg has are set
     */
    public function switchLoader(object $server, int $targetEggId, ?string $mcVersion = null, bool $requireBackup = true, array $envOverrides = []): array
    {
        $uuid = $server->uuid;

        // Every loader switch wipes the server and relies on the reinstall to
        // put a jar back; refuse before stopping/wiping if it can't run (#3).
        ReinstallGuard::assertCanReinstall($server, 'switch loader');
        GameGuard::assertMinecraft($server, 'switch loader');

        if (! class_exists(self::EGG_MODEL)) {
            throw new \RuntimeException('Egg model unavailable on this panel.');
        }
        $eggModel = self::EGG_MODEL;
        $egg = $eggModel::with('variables')->find($targetEggId);
        if (! $egg) {
            throw new \RuntimeException("Target egg {$targetEggId} not found.");
        }

        if ($requireBackup && ! $this->hasRecentCompletedBackup($uuid)) {
            throw new \RuntimeException('A completed backup is required before switching loader. Take one first.');
        }

        // Must be offline before we wipe files. Stop and let the caller poll.
        $state = $this->ops->runtimeState($uuid);
        if (($state['power'] ?? null) !== 'offline') {
            app(AutoStartService::class)->stoppedForSwitch($uuid);
            try {
                $this->ops->power($uuid, 'stop');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not stop the server before the loader reset: ' . $e->getMessage());
            }

            return ['status' => 'stopping'];
        }

        // Determine the Minecraft version to carry over.
        $mc = $mcVersion;
        if ($mc === null || $mc === '') {
            $current = $this->versions->current($server);
            $mc = (string) ($current['game_version'] ?? '');
        }

        $env = $this->buildEnvironment($egg, $mc);
        foreach ($envOverrides as $key => $value) {
            if (array_key_exists($key, $env)) {
                $env[$key] = (string) $value;
            }
        }
        $image = $this->resolveEggImage($egg, $mc);
        // A direct jar from MCJars (#22) for eggs that take DL_PATH.
        $pin = app(McJarsService::class)->pinFor(McJarsService::keywordForEgg((string) $egg->name), $mc);
        if ($pin !== null && array_key_exists('DL_PATH', $env)) {   // $env holds every variable the egg has
            $env['DL_PATH'] = $pin['url'];
            if (array_key_exists('SERVER_JARFILE', $env)) {
                $env['SERVER_JARFILE'] = $pin['file'];
            }
        }

        // Swap egg + variables + startup and set the target-egg image first
        // (#141/#139 item 70): if the egg refuses a value, nothing is wiped.
        $this->ops->changeEgg($uuid, $targetEggId, $env);
        if ($image) {
            $this->ops->setDockerImage($uuid, $image);
        }

        // Mandatory clean slate — wipe everything but eula.txt.
        $wiped = $this->files->wipeForNuclear($uuid, 'clean_slate');

        // Every file is gone, so the mod manifest must be emptied to match
        // (auto-reconcile) — otherwise the Mods tab lists mods that no longer exist.
        try {
            $this->manifest->clearAll($uuid);
        } catch (\Throwable $e) {
            // non-fatal
        }

        $this->ops->reinstall($uuid);

        // Tell the Mod Manager the loader/version changed, or it keeps operating
        // as the old loader — looking in the wrong install dir (plugins vs mods),
        // querying repositories with the wrong loader, and never re-detecting.
        $loaderSlug = $this->deriveLoaderSlug((string) $egg->name);
        try {
            // An egg we can't name clears the old loader rather than keeping
            // it (#137): detection then works it out from the new egg.
            $update = ['game_version' => $mc, 'loader' => $loaderSlug, 'loader_locked' => false,
                'install_path' => $loaderSlug !== null ? (config("serversuite.mod_directories.{$loaderSlug}") ?: null) : null];
            \ServerSuite\Models\ModManagerSetting::forServer($uuid)->update($update);
        } catch (\Throwable $e) {
            // non-fatal — detection will still recover it from the egg name
        }

        return [
            'status'      => 'reinstalling',
            'egg'         => ['id' => $egg->id, 'name' => $egg->name],
            'loader'      => $loaderSlug,
            'mc_version'  => $mc,
            'image'       => $image,
            'wiped'       => $wiped,
            'environment' => $env,
        ];
    }

    /**
     * Realign a server's egg to a restored backup's loader — WITHOUT wiping or
     * reinstalling. A cross-loader restore brings back the other loader's files
     * but leaves the server configured for the old loader (wrong egg/startup/
     * variables), so nothing detects the restored loader. This swaps only the
     * config (egg_id, startup, variables, image, stored settings) so it matches
     * the files the restore already placed.
     *
     * Returns null (caller falls back to version-only alignment) when there's no
     * installed egg for that loader, or the server is already on the right egg.
     *
     * @return array|null
     */
    public function alignLoaderForRestore(object $server, string $loaderSlug, string $mcVersion): ?array
    {
        if (! class_exists(self::EGG_MODEL)) {
            return null;
        }
        $eggModel = self::EGG_MODEL;

        // Only an egg the Versions tab offers for that loader (Java server
        // loaders, no Bedrock or proxy eggs), never any egg whose name happens
        // to contain the word (#119).
        $offered = collect(app(EggManagerService::class)->minecraftLoaders())->firstWhere('loader', $loaderSlug);
        $egg = $offered ? $eggModel::with('variables')->find((int) $offered['egg_id']) : null;
        if ($egg === null) {
            return null;
        }

        // Already on the right egg -> same-loader restore, nothing to switch.
        if ((int) ($server->egg_id ?? 0) === (int) $egg->id) {
            return null;
        }

        $env = $this->buildEnvironment($egg, $mcVersion);
        $this->ops->changeEgg($server->uuid, (int) $egg->id, $env);

        $image = $this->resolveEggImage($egg, $mcVersion);
        if ($image) {
            $this->ops->setDockerImage($server->uuid, $image);
        }

        try {
            $update = ['game_version' => $mcVersion, 'loader' => $loaderSlug];
            $path = config("serversuite.mod_directories.{$loaderSlug}");
            if ($path) {
                $update['install_path'] = $path;
            }
            \ServerSuite\Models\ModManagerSetting::forServer($server->uuid)->update($update);
        } catch (\Throwable $e) {
            // non-fatal
        }

        return [
            'loader_switched' => true,
            'loader'          => $loaderSlug,
            'egg'             => ['id' => $egg->id, 'name' => $egg->name],
            'version'         => $mcVersion,
        ];
    }

    /**
     * Map a target egg's name to a loader slug (neoforge before forge so the
     * more specific name wins). Returns null if nothing recognisable matches.
     */
    private function deriveLoaderSlug(string $eggName): ?string
    {
        // Most specific first: neoforge before forge, Paper forks before paper.
        // Hybrids that load Forge mods count as forge (#137).
        $name = strtolower($eggName);
        foreach ([
            'neoforge' => 'neoforge', 'fabric' => 'fabric', 'quilt' => 'quilt',
            'mohist' => 'forge', 'arclight' => 'forge', 'forge' => 'forge',
            'folia' => 'folia', 'purpur' => 'purpur', 'pufferfish' => 'pufferfish', 'paper' => 'paper', 'spigot' => 'spigot',
            'waterfall' => 'waterfall', 'bungeecord' => 'bungeecord', 'velocity' => 'velocity', 'sponge' => 'sponge', 'vanilla' => 'vanilla',
        ] as $word => $slug) {
            if (str_contains($name, $word)) {
                return $slug;
            }
        }

        return null;
    }

    private function hasRecentCompletedBackup(string $serverUuid, int $withinMinutes = 60): bool
    {
        return \ServerSuite\Support\RecentBackup::exists($this->ops, $serverUuid, $withinMinutes);
    }

}
