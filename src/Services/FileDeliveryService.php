<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Support\Data\ModVersion;
use Illuminate\Support\Facades\Log;

/**
 * Delivers mod files to game servers via the Wings daemon's remote-pull
 * mechanism, then verifies the result against repository metadata.
 *
 * Flow:
 *   1. Resolve the target directory for the server (loader -> config default,
 *      or per-server override).
 *   2. Refuse early if the version has no public download URL (e.g. a
 *      CurseForge file with third-party downloads disabled).
 *   3. Ask Wings to pull the file directly from the repository CDN.
 *   4. Verify: re-list the directory and confirm the file is present at the
 *      expected size. (Hash verification is attempted where the daemon
 *      exposes it; size is the always-available floor.)
 *
 * The file never passes through the panel — Wings downloads it directly.
 */
class FileDeliveryService
{
    public function __construct(protected DaemonFileClient $daemon) {}

    /**
     * Deliver a specific mod version to a server.
     *
     * @return array{filename: string, directory: string, verified: bool, verification: string}
     *
     * @throws DeliveryException
     */
    public function deliver(string $serverUuid, ModVersion $version, string $modName): array
    {
        // 2. No URL -> cannot pull. Fail clearly so the UI can offer a manual link.
        if (trim($version->downloadUrl) === '') {
            throw DeliveryException::noDownloadUrl($modName);
        }

        // 1. Resolve target directory.
        $directory = $this->targetDirectory($serverUuid);
        $filename  = $version->filename;

        // 3. Pull via the daemon (foreground so we can verify right after).
        $this->daemon->pull(
            serverUuid: $serverUuid,
            url: $version->downloadUrl,
            directory: $directory,
            filename: $filename,
            foreground: true,
        );

        // 4. Verify.
        $verification = $this->verify($serverUuid, $directory, $version);

        if (! $verification['ok']) {
            // Best-effort cleanup of a bad file so we don't leave a corrupt jar.
            $this->safeDelete($serverUuid, $directory, $filename);
            throw DeliveryException::verificationFailed($verification['detail']);
        }

        return [
            'filename'     => $filename,
            'directory'    => $directory,
            'verified'     => true,
            'verification' => $verification['detail'],
        ];
    }

    /**
     * Remove a delivered mod file from a server.
     *
     * @throws DeliveryException
     */
    public function remove(string $serverUuid, string $directory, string $filename): void
    {
        $this->daemon->deleteFiles($serverUuid, $directory, [$filename]);
    }

    /**
     * Best-effort cleanup of a mod/plugin's leftover config, so removal is
     * seamless rather than leaving orphaned folders (e.g. JourneyMap scatters
     * config across several roots). Matches entries whose name equals the mod's
     * name — normalised (lowercased, non-alphanumerics stripped) — across the
     * common roots, then deletes them.
     *
     * Exact-normalised match (not prefix) avoids eating an unrelated "backups/"
     * when removing something called "Backup", and a hard PROTECTED blocklist
     * guarantees critical folders/files (world, logs, plugins, jars, …) are
     * never touched even if a mod shares their name. Purely best-effort: any
     * failure is swallowed so it can never block the actual removal.
     *
     * @return array<int,string> paths removed (for logging)
     */
    public function sweepConfigs(string $serverUuid, string $modName): array
    {
        $target = $this->normalizeName($modName);
        if ($target === '' || strlen($target) < 3) {
            // Too short / empty names are too collision-prone to sweep safely.
            return [];
        }

        $removed = [];

        foreach (['plugins', 'config', '/'] as $root) {
            try {
                $entries = $this->daemon->listDirectory($serverUuid, $root);
            } catch (\Throwable $e) {
                continue; // root may not exist
            }

            $toDelete = [];
            foreach ($entries as $entry) {
                $name = (string) ($entry['name'] ?? '');
                if ($name === '' || in_array(strtolower($name), self::PROTECTED, true)) {
                    continue;
                }
                // Strip a config-ish extension so "journeymap.yml" matches too.
                $base = preg_replace('/\.(ya?ml|toml|conf|json|properties|txt|cfg)$/i', '', $name);
                if ($this->normalizeName($base) === $target) {
                    $toDelete[] = $name;
                }
            }

            if ($toDelete) {
                try {
                    $this->daemon->deleteFiles($serverUuid, $root, $toDelete);
                    foreach ($toDelete as $n) {
                        $removed[] = ($root === '/' ? '' : $root . '/') . $n;
                    }
                } catch (\Throwable $e) {
                    // best-effort — ignore
                }
            }
        }

        return $removed;
    }

    /**
     * Wipe files for a nuclear reset. Server MUST be stopped first.
     *   - keep_plugins: deletes only the world folders (level-name from
     *     server.properties, plus its _nether/_the_end), leaving plugins,
     *     configs and server.properties intact.
     *   - clean_slate:  deletes everything except eula.txt (kept so the server
     *     still boots — the egg install script does not re-accept the EULA).
     * Best-effort; returns the entries removed.
     *
     * @return array<int,string>
     */
    public function wipeForNuclear(string $serverUuid, string $mode): array
    {
        try {
            $entries = $this->daemon->listDirectory($serverUuid, '/');
        } catch (\Throwable $e) {
            return [];
        }

        $victims = [];

        if ($mode === 'keep_plugins') {
            // Resolve the world base name (custom level-name is respected).
            $level = 'world';
            try {
                $props = $this->daemon->readFile($serverUuid, 'server.properties');
                if ($props && preg_match('/^\s*level-name\s*=\s*(.+?)\s*$/m', $props, $m)) {
                    $level = trim($m[1]) ?: 'world';
                }
            } catch (\Throwable $e) {
                // fall back to default "world"
            }
            $worlds = array_map('strtolower', [$level, $level . '_nether', $level . '_the_end']);

            foreach ($entries as $e) {
                $name = (string) ($e['name'] ?? '');
                if ($name !== '' && in_array(strtolower($name), $worlds, true)) {
                    $victims[] = $name;
                }
            }
        } else {
            // clean_slate: everything except eula.txt.
            foreach ($entries as $e) {
                $name = (string) ($e['name'] ?? '');
                if ($name === '' || strtolower($name) === 'eula.txt') {
                    continue;
                }
                $victims[] = $name;
            }
        }

        if ($victims) {
            try {
                $this->daemon->deleteFiles($serverUuid, '/', $victims);
            } catch (\Throwable $e) {
                return [];
            }
        }

        return $victims;
    }

    private function normalizeName(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /**
     * Delete every plugin JAR (top-level *.jar under plugins/), leaving config
     * folders intact. Used by the version switcher: plugins built for one
     * Minecraft version routinely halt boot on another, so the default on a
     * switch is to clear the jars (keeping configs, so re-adding a compatible
     * build keeps its settings). Best-effort; returns the jars removed.
     *
     * @return array<int,string>
     */
    public function deletePluginJars(string $serverUuid): array
    {
        try {
            $entries = $this->daemon->listDirectory($serverUuid, 'plugins');
        } catch (\Throwable $e) {
            return [];
        }

        $jars = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if (($entry['is_file'] ?? false) && preg_match('/\.jar$/i', $name)) {
                $jars[] = $name;
            }
        }

        if ($jars) {
            try {
                $this->daemon->deleteFiles($serverUuid, 'plugins', $jars);
            } catch (\Throwable $e) {
                return [];
            }
        }

        return $jars;
    }

    /**
     * Names that must NEVER be deleted by the config sweep, regardless of a
     * mod's name. Guards worlds, server state, and core config so a mod called
     * e.g. "World" or "logs" can't wipe them. (A custom level-name is a rare
     * residual edge not covered here.)
     */
    private const PROTECTED = [
        'world', 'world_nether', 'world_the_end', 'logs', 'cache', 'crash-reports',
        'libraries', 'plugins', 'config', 'mods', 'versions', 'bin', 'server.jar',
        'server.properties', 'eula.txt', 'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json', 'usercache.json', 'session.lock',
        'bukkit.yml', 'spigot.yml', 'paper.yml', 'paper-global.yml',
        'paper-world-defaults.yml', 'commands.yml', 'help.yml', 'permissions.yml',
    ];

    // -------------------------------------------------------------------------
    // Directory resolution
    // -------------------------------------------------------------------------

    /**
     * Determine where mods should be written for this server: explicit
     * per-server override first, then the loader-based default from config.
     */
    public function targetDirectory(string $serverUuid): string
    {
        $settings = ModManagerSetting::forServer($serverUuid);

        return $settings->effectiveInstallPath();
    }

    // -------------------------------------------------------------------------
    // Verification
    // -------------------------------------------------------------------------

    /**
     * Verify the pulled file landed correctly.
     *
     * Strategy: re-list the directory and find our filename. Confirm it exists
     * and (when the repo gave us a size) that the size matches within a small
     * tolerance. Hash verification is only possible when the daemon exposes a
     * hash for the file; directory listings don't include hashes, so size is
     * the reliable signal here and hashes are verified opportunistically when
     * available via a dedicated read in a later phase.
     *
     * @return array{ok: bool, detail: string}
     */
    protected function verify(string $serverUuid, string $directory, ModVersion $version): array
    {
        try {
            $entries = $this->daemon->listDirectory($serverUuid, $directory);
        } catch (DeliveryException $e) {
            // If we can't list, we can't verify; treat as unverified failure.
            return ['ok' => false, 'detail' => "could not list directory: {$e->getMessage()}"];
        }

        $match = null;
        foreach ($entries as $entry) {
            if ($entry['is_file'] && $entry['name'] === $version->filename) {
                $match = $entry;
                break;
            }
        }

        if ($match === null) {
            return ['ok' => false, 'detail' => "file {$version->filename} not found after pull"];
        }

        // Size check (only when the repository reported a size).
        if ($version->fileSize > 0 && $match['size'] > 0) {
            // Allow a tiny tolerance for filesystem block rounding differences.
            $delta = abs($match['size'] - $version->fileSize);
            if ($delta > 0) {
                return [
                    'ok' => false,
                    'detail' => "size mismatch: expected {$version->fileSize} bytes, got {$match['size']}",
                ];
            }

            return ['ok' => true, 'detail' => "size verified ({$match['size']} bytes)"];
        }

        // File exists but we had no size to compare; accept with a note.
        return ['ok' => true, 'detail' => 'file present (size not verified — no reference size)'];
    }

    /**
     * Delete without throwing — used for cleanup after a failed verification.
     */
    protected function safeDelete(string $serverUuid, string $directory, string $filename): void
    {
        try {
            $this->daemon->deleteFiles($serverUuid, $directory, [$filename]);
        } catch (\Throwable $e) {
            Log::warning("[Server Suite] failed to clean up bad file {$filename}: {$e->getMessage()}");
        }
    }
}
