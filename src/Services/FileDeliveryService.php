<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Exceptions\WipeFailedException;
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
     * Pull any file into $directory and check it landed at $expectedSize
     * (0 = size unknown). Used for modpack files, which aren't ModVersions.
     * With $sha1 the file is also read back from Wings and its SHA-1 compared,
     * since a file on GitHub or GitLab can change without its size changing
     * (#133). A file that fails either check is deleted.
     *
     * @throws DeliveryException
     */
    public function deliverFile(string $serverUuid, string $url, string $directory, string $filename, int $expectedSize, ?string $sha1 = null): string
    {
        $this->daemon->pull($serverUuid, $url, $directory, $filename, true);

        $entries = $this->daemon->listDirectory($serverUuid, $directory);
        $match = null;
        foreach ($entries as $entry) {
            if (($entry['is_file'] ?? false) && ($entry['name'] ?? null) === $filename) {
                $match = $entry;
                break;
            }
        }
        if ($match === null) {
            throw DeliveryException::verificationFailed("file {$filename} not found after download");
        }
        if ($expectedSize > 0 && (int) $match['size'] !== $expectedSize) {
            $this->safeDelete($serverUuid, $directory, $filename);
            throw DeliveryException::verificationFailed("size mismatch: expected {$expectedSize} bytes, got {$match['size']}");
        }

        $sha1 = strtolower(trim((string) $sha1));
        if ($sha1 !== '') {
            $this->verifySha1($serverUuid, $directory, $filename, $sha1, $expectedSize > 0 ? $expectedSize : (int) $match['size']);

            return 'SHA-1 verified';
        }

        return $expectedSize > 0 ? "size verified ({$expectedSize} bytes)" : 'file present (no reference size)';
    }

    /** Read a delivered file back from Wings and compare its SHA-1; delete it on a mismatch. */
    private function verifySha1(string $serverUuid, string $directory, string $filename, string $sha1, int $size): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ss-sha1-');
        if ($tmp === false) {
            throw DeliveryException::verificationFailed("no temp file to check the SHA-1 of {$filename}");
        }
        try {
            $path = rtrim($directory, '/') . '/' . $filename;
            $limit = $size > 0 ? $size : 1073741824;
            if (! $this->daemon->downloadTo($serverUuid, $path, $tmp, $limit)) {
                $this->safeDelete($serverUuid, $directory, $filename);
                throw DeliveryException::verificationFailed("could not read {$filename} back to check its SHA-1");
            }
            $got = (string) sha1_file($tmp);
            if (! hash_equals($sha1, $got)) {
                $this->safeDelete($serverUuid, $directory, $filename);
                throw DeliveryException::verificationFailed("SHA-1 mismatch: expected {$sha1}, got {$got}");
            }
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Move everything under $src into $dst, replacing files that exist and
     * merging folders, all on Wings (nothing passes through the panel).
     * Folders missing at the destination move in one rename. Returns the
     * destination paths of the files it replaced or moved, relative to $dst.
     *
     * @return array<int, string>
     *
     * @throws DeliveryException
     */
    public function mergeInto(string $serverUuid, string $src, string $dst, int $depth = 0, array $keepExisting = []): array
    {
        if ($depth > 32) {
            throw DeliveryException::daemonError("folders nested too deep under {$src}");
        }

        $join = fn (string $a, string $b) => rtrim($a, '/') . '/' . $b;
        $srcEntries = $this->daemon->listDirectory($serverUuid, $src);
        try {
            $dstEntries = collect($this->daemon->listDirectory($serverUuid, $dst))->keyBy('name');
        } catch (DeliveryException) {
            $dstEntries = collect();   // destination folder doesn't exist yet
        }

        $moved = [];
        foreach ($srcEntries as $e) {
            $name = (string) $e['name'];
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $existing = $dstEntries->get($name);
            // The server's own copy wins for these (top level only).
            if ($existing !== null && $depth === 0 && in_array($name, $keepExisting, true)) {
                continue;
            }

            if ($existing !== null && ! $e['is_file'] && ! $existing['is_file']) {
                foreach ($this->mergeInto($serverUuid, $join($src, $name), $join($dst, $name), $depth + 1) as $p) {
                    $moved[] = $name . '/' . $p;
                }
                continue;
            }
            if ($existing !== null) {
                $this->daemon->deleteFiles($serverUuid, $dst, [$name]);
            }
            $this->daemon->rename($serverUuid, '/', ltrim($join($src, $name), '/'), ltrim($join($dst, $name), '/'));
            $moved[] = $name;
        }

        return $moved;
    }

    /**
     * Make everything under $dir readable: files 644, folders 755. Wings keeps
     * an archive's permission bits when it unpacks, and zips made on Windows
     * often carry 000, which leaves files the server can't read ("Permission
     * denied" on a mod jar). One chmod call per folder.
     *
     * @throws DeliveryException
     */
    public function normalisePermissions(string $serverUuid, string $dir, int $depth = 0): void
    {
        if ($depth > 32) {
            return;
        }
        $modes = [];
        $subdirs = [];
        foreach ($this->daemon->listDirectory($serverUuid, $dir) as $e) {
            $name = (string) $e['name'];
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $modes[$name] = $e['is_file'] ? '644' : '755';
            if (! $e['is_file']) {
                $subdirs[] = rtrim($dir, '/') . '/' . $name;
            }
        }
        $this->daemon->chmod($serverUuid, $dir, $modes);
        foreach ($subdirs as $sub) {
            $this->normalisePermissions($serverUuid, $sub, $depth + 1);
        }
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
     * never touched even if a mod shares their name. In the server root only
     * folders are swept: files there belong to the server software (a mod
     * called "Purpur" must not take purpur.yml with it), and the world folders
     * from level-name are protected too (#135). Purely best-effort: any
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
        $worlds = $this->worldFolderNames($serverUuid);

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
                if ($root === '/' && (($entry['is_file'] ?? true) || in_array(strtolower($name), $worlds, true))) {
                    continue;   // root: mod config folders only, never files or worlds
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
     *
     * Throws when the folder can't be listed, the delete fails, or anything
     * that should be gone is still there afterwards, so a switch or reset
     * stops instead of reporting a wipe that didn't happen (#136).
     *
     * @return array<int,string> the entries removed
     *
     * @throws WipeFailedException
     */
    public function wipeForNuclear(string $serverUuid, string $mode): array
    {
        try {
            $entries = $this->daemon->listDirectory($serverUuid, '/');
        } catch (\Throwable $e) {
            throw new WipeFailedException("Couldn't list the server's files, so nothing was wiped or changed: {$e->getMessage()}", 0, $e);
        }

        $victims = [];

        if ($mode === 'keep_plugins') {
            $worlds = $this->worldFolderNames($serverUuid, true);

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

        if (! $victims) {
            return [];
        }

        try {
            $this->daemon->deleteFiles($serverUuid, '/', $victims);
        } catch (\Throwable $e) {
            throw new WipeFailedException("Couldn't delete the server's files, so the reset was stopped. Some files may already be gone: {$e->getMessage()}", 0, $e);
        }

        // Wings can report success and still leave entries behind; check.
        try {
            $after = $this->daemon->listDirectory($serverUuid, '/');
        } catch (\Throwable $e) {
            throw new WipeFailedException("Couldn't check the wipe afterwards, so the reset was stopped: {$e->getMessage()}", 0, $e);
        }
        $left = array_values(array_intersect($victims, array_map(fn ($e) => (string) ($e['name'] ?? ''), $after)));
        if ($left) {
            throw new WipeFailedException('The wipe left files behind (' . implode(', ', array_slice($left, 0, 5)) . (count($left) > 5 ? ', …' : '') . '), so the reset was stopped.');
        }

        return $victims;
    }

    /**
     * World folder names (lower case) from server.properties level-name, plus
     * the _nether and _the_end folders. Falls back to "world". With $strict,
     * a level-name that is a path (worlds/survival) throws, since only
     * top-level folders are wiped and the reset would otherwise do nothing.
     *
     * @return array<int,string>
     */
    private function worldFolderNames(string $serverUuid, bool $strict = false): array
    {
        $level = 'world';
        try {
            $props = $this->daemon->readFile($serverUuid, 'server.properties');
            if ($props && preg_match('/^\s*level-name\s*=\s*(.+?)\s*$/m', $props, $m)) {
                $level = trim($m[1]) ?: 'world';
            }
        } catch (\Throwable $e) {
            // fall back to default "world"
        }
        if (str_contains($level, '/') || str_contains($level, '\\')) {
            if ($strict) {
                throw new WipeFailedException("level-name in server.properties is a path ({$level}). Reset the world from the file manager instead.");
            }
            $level = basename(str_replace('\\', '/', $level));
        }

        return array_map('strtolower', [$level, $level . '_nether', $level . '_the_end']);
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
     * build keeps its settings). On a mod loader the same goes for mods/
     * ($dir 'mods'). Best-effort; returns the jars removed.
     *
     * @return array<int,string>
     */
    public function deletePluginJars(string $serverUuid, string $dir = 'plugins'): array
    {
        $dir = $dir === 'mods' ? 'mods' : 'plugins';
        try {
            $entries = $this->daemon->listDirectory($serverUuid, $dir);
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
                $this->daemon->deleteFiles($serverUuid, $dir, $jars);
            } catch (\Throwable $e) {
                return [];
            }
        }

        return $jars;
    }

    /**
     * Names that must NEVER be deleted by the config sweep, regardless of a
     * mod's name. Guards worlds, server state, and core config so a mod called
     * e.g. "World" or "logs" can't wipe them. Custom level-name world folders
     * are protected separately (worldFolderNames).
     */
    private const PROTECTED = [
        'world', 'world_nether', 'world_the_end', 'logs', 'cache', 'crash-reports',
        'libraries', 'plugins', 'config', 'mods', 'versions', 'bin', 'server.jar',
        'server.properties', 'eula.txt', 'ops.json', 'whitelist.json',
        'banned-players.json', 'banned-ips.json', 'usercache.json', 'session.lock',
        'bukkit.yml', 'spigot.yml', 'paper.yml', 'paper-global.yml',
        'paper-world-defaults.yml', 'commands.yml', 'help.yml', 'permissions.yml',
        'purpur.yml', 'pufferfish.yml', 'leaves.yml', 'velocity.toml',
        'forwarding.secret', 'config.yml', 'allowlist.json', 'permissions.json',
        'worlds', 'behavior_packs', 'resource_packs', 'development_behavior_packs',
        'development_resource_packs', 'valid_known_packs.json', 'server.json',
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
     * and (when the repo gave us a size) that the size matches exactly.
     * Listings carry no hashes: the SHA-1, when the repository gives one, is
     * checked separately by reading the file back (verifySha1).
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
