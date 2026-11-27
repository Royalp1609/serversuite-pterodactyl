<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\DB;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Support\SuiteActivity;

/**
 * After any reinstall: make sure the egg's startup finds a jar to run.
 *
 * The Minecraft eggs start `-jar {{SERVER_JARFILE}}` (default server.jar),
 * or `@unix_args.txt` when the Forge/NeoForge install wrote one. Newer
 * loaders don't always leave what the egg's install script expects: Forge
 * for Minecraft 26.x installs forge-<ver>-shim.jar and no unix_args.txt,
 * and the Forge egg's rename to server.jar fails, so the server can't start
 * ("Unable to access jarfile server.jar", seen on the test panel). When the
 * configured jar is missing, this points SERVER_JARFILE at the launch jar
 * the install did produce. It also repairs a dangling unix_args.txt link.
 *
 * Runs from SyncAfterInstallJob when a server's status leaves "installing",
 * so it covers the Versions tab, Settings -> Reinstall, admins and modpack
 * switches alike. Ported from the Pelican plugin's PostSwitchService. Never
 * throws.
 */
class PostSwitchService
{
    public function __construct(
        protected DaemonFileClient $daemon,
        protected PanelServerOps $ops,
    ) {}

    /** @return array<int, array{title: string, body: string}> what was fixed */
    public function finish(string $uuid): array
    {
        $notes = [];
        try {
            $entries = $this->daemon->listDirectory($uuid, '/');
            $files = collect($entries)->where('is_file', true)->pluck('name')->map(fn ($n) => (string) $n)->all();

            if ($this->repairSpongeJar($uuid, $entries, $notes)) {
                return $notes;
            }

            if (in_array('unix_args.txt', $files, true) && $this->repairArgsFile($uuid, $notes)) {
                return $notes;   // the egg starts @unix_args.txt; SERVER_JARFILE isn't used
            }

            $current = $this->currentJar($uuid);
            if ($current === null || in_array($current, $files, true)) {
                return $notes;   // no such variable, or its jar is there
            }

            $pick = $this->launchJar($files);
            if ($pick === null) {
                return $notes;
            }

            $this->ops->setServerVariables($uuid, ['SERVER_JARFILE' => $pick]);
            SuiteActivity::logFor($uuid, 'server:serversuite.jar-aligned', ['jar' => $pick, 'was' => $current]);
            $notes[] = ['title' => 'Server jar aligned', 'body' => "SERVER_JARFILE {$current} -> {$pick}"];
        } catch (\Throwable) {
            // listing failed or the egg has no SERVER_JARFILE: never block the start
        }

        return $notes;
    }

    /**
     * The jar the loader's install leaves to start the server with.
     *
     * @param string[] $files names in the server's root
     */
    public function launchJar(array $files): ?string
    {
        $jars = array_values(array_filter($files, fn ($f) => str_ends_with(strtolower($f), '.jar')));
        foreach (['/^fabric-server-launch\.jar$/i', '/^(neo)?forge-.*-shim\.jar$/i', '/^quilt-server-launch\.jar$/i', '/^server\.jar$/i'] as $pattern) {
            foreach ($jars as $j) {
                if (preg_match($pattern, $j)) {
                    return $j;
                }
            }
        }

        return null;
    }

    /** The server's SERVER_JARFILE value (or the egg default), or null when the egg has none. */
    /**
     * The stock Sponge egg downloads spongevanilla-<version>.jar, which for
     * current SpongeVanilla is a ~50 KB stub with no Main-Class ("no main
     * manifest attribute, in server.jar"); the server runs from the
     * -universal jar (~8 MB). When the egg's jar is that stub, put the
     * universal jar for the same SPONGE_VERSION in its place. Returns whether
     * it did.
     *
     * @param array<int, array<string, mixed>> $entries the server root
     */
    private function repairSpongeJar(string $uuid, array $entries, array &$notes): bool
    {
        $version = $this->variable($uuid, 'SPONGE_VERSION');
        $jar = $this->currentJar($uuid);
        if ($version === null || $jar === null || ! preg_match('/^\d+\.\d+(?:\.\d+)?-[A-Za-z0-9.+-]+$/', $version) || ! preg_match('/^[\w.+-]+\.jar$/', $jar)) {
            return false;
        }
        $entry = collect($entries)->first(fn ($e) => ($e['is_file'] ?? false) && (string) $e['name'] === $jar);
        if ($entry !== null && (int) ($entry['size'] ?? 0) >= self::SPONGE_STUB_MAX) {
            return false;   // a real jar (or someone's own): leave it
        }
        $url = "https://repo.spongepowered.org/repository/maven-releases/org/spongepowered/spongevanilla/{$version}/spongevanilla-{$version}-universal.jar";
        if ($entry !== null) {
            $this->daemon->deleteFiles($uuid, '/', [$jar]);
        }
        $this->daemon->pull($uuid, $url, '/', $jar, true);
        SuiteActivity::logFor($uuid, 'server:serversuite.jar-aligned', ['jar' => $jar, 'was' => "spongevanilla-{$version}.jar (stub)"]);
        $notes[] = ['title' => 'Sponge jar replaced', 'body' => "The Sponge egg downloaded a jar that can't start; installed spongevanilla-{$version}-universal.jar as {$jar}."];

        return true;
    }

    /** Below this, a SpongeVanilla jar is the no-Main-Class stub (54 KB) rather than the universal jar (8 MB). */
    private const SPONGE_STUB_MAX = 1048576;

    private function variable(string $uuid, string $env): ?string
    {
        $row = DB::table('servers as s')
            ->join('egg_variables as v', 'v.egg_id', '=', 's.egg_id')
            ->leftJoin('server_variables as sv', fn ($j) => $j->on('sv.variable_id', '=', 'v.id')->on('sv.server_id', '=', 's.id'))
            ->where('s.uuid', $uuid)->where('v.env_variable', $env)
            ->first(['sv.variable_value', 'v.default_value']);
        if ($row === null) {
            return null;
        }
        $value = trim((string) ($row->variable_value ?? ''));

        return $value !== '' ? $value : (trim((string) $row->default_value) ?: null);
    }

    private function currentJar(string $uuid): ?string
    {
        $row = DB::table('servers as s')
            ->join('egg_variables as v', 'v.egg_id', '=', 's.egg_id')
            ->leftJoin('server_variables as sv', fn ($j) => $j->on('sv.variable_id', '=', 'v.id')->on('sv.server_id', '=', 's.id'))
            ->where('s.uuid', $uuid)->where('v.env_variable', 'SERVER_JARFILE')
            ->first(['sv.variable_value', 'v.default_value']);
        if ($row === null) {
            return null;
        }
        $value = trim((string) ($row->variable_value ?? ''));

        return $value !== '' ? $value : (trim((string) $row->default_value) ?: null);
    }

    /**
     * NeoForge/modern Forge boot via @unix_args.txt, which some install
     * scripts create as a symlink with an ABSOLUTE target (/mnt/server/...)
     * that dangles at runtime (/home/container). If it can't be read but the
     * real file is under libraries/, write it out as a regular file (its
     * paths are relative). Returns whether unix_args.txt is usable.
     */
    private function repairArgsFile(string $uuid, array &$notes): bool
    {
        try {
            $raw = $this->daemon->readFile($uuid, '/unix_args.txt');
        } catch (\Throwable) {
            $raw = null;
        }
        if ($raw !== null && trim($raw) !== '') {
            return true;
        }

        foreach (['/libraries/net/neoforged/neoforge', '/libraries/net/minecraftforge/forge'] as $base) {
            try {
                foreach ($this->daemon->listDirectory($uuid, $base) as $d) {
                    if (! empty($d['is_file'])) {
                        continue;
                    }
                    try {
                        $args = $this->daemon->readFile($uuid, $base . '/' . $d['name'] . '/unix_args.txt');
                    } catch (\Throwable) {
                        continue;
                    }
                    if ($args !== null && trim($args) !== '') {
                        // Wings resolves symlinks on write, so the dangling
                        // link has to go before the real file is written.
                        try {
                            $this->daemon->deleteFiles($uuid, '/', ['unix_args.txt']);
                        } catch (\Throwable) {
                        }
                        $this->daemon->writeFile($uuid, '/unix_args.txt', $args);
                        SuiteActivity::logFor($uuid, 'server:serversuite.args-repaired', ['from' => $base . '/' . $d['name']]);
                        $notes[] = ['title' => 'Launch args repaired', 'body' => 'unix_args.txt was a broken link, recreated from ' . $d['name'] . '.'];

                        return true;
                    }
                }
            } catch (\Throwable) {
                // not this loader
            }
        }

        return false;
    }
}
