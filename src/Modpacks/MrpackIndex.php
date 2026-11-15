<?php

namespace ServerSuite\Modpacks;

use ServerSuite\Exceptions\ModpackException;

/**
 * A parsed and checked modpack index (#23): a Modrinth .mrpack
 * (modrinth.index.json, format 1; parse()) or a CurseForge pack
 * (manifest.json with files resolved through the API; fromCurseForge()).
 * See https://support.modrinth.com/en/articles/8802351-modrinth-modpack-format-mrpack
 *
 * Pure: no I/O. It is the safety boundary between a pack (untrusted input)
 * and Wings, so every file is classified here:
 *   install      - a server file with a download on an allowed host
 *   client_only  - env.server is "unsupported" (client mods, shaders, ...)
 *   blocked      - no download URL on the hosts the format allows
 *   invalid      - the path could escape the server folder or is malformed
 */
final class MrpackIndex
{
    /** Hosts the format allows downloads from (the Modrinth launcher enforces the same). */
    public const ALLOWED_HOSTS = ['cdn.modrinth.com', 'github.com', 'raw.githubusercontent.com', 'gitlab.com'];

    /** CurseForge's CDN. */
    public const CURSEFORGE_HOSTS = ['edge.forgecdn.net', 'mediafilez.forgecdn.net'];

    /** Pack dependency key -> the plugin's loader slug. */
    private const LOADERS = [
        'fabric-loader' => 'fabric',
        'quilt-loader'  => 'quilt',
        'forge'         => 'forge',
        'neoforge'      => 'neoforge',
    ];

    /**
     * @param array<int, array{path: string, sha1: string, sha512: ?string, size: int, url: ?string, action: string, reason: ?string}> $files
     */
    private function __construct(
        public readonly string $name,
        public readonly string $versionId,
        public readonly string $minecraft,
        public readonly ?string $loader,
        public readonly ?string $loaderVersion,
        public readonly array $files,
        public readonly string $sha1,
        public readonly string $indexFile = 'modrinth.index.json',
        public readonly array $overrideDirs = ['overrides', 'server-overrides'],
    ) {}

    /**
     * A CurseForge pack: manifest.json plus its files as returned by
     * POST /v1/mods/files (file id => data). Jars go to mods/; resource and
     * shader packs (zips) are client-side and skipped. Files whose author
     * turned off third-party downloads have no URL and are listed as blocked.
     *
     * @param array<string, array> $files
     *
     * @throws ModpackException
     */
    public static function fromCurseForge(string $json, array $files): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ModpackException('The modpack manifest (manifest.json) is not valid JSON.');
        }
        if (! is_array($data) || ($data['manifestType'] ?? null) !== 'minecraftModpack') {
            throw new ModpackException('This is not a CurseForge Minecraft modpack.');
        }

        $minecraft = (string) ($data['minecraft']['version'] ?? '');
        if ($minecraft === '') {
            throw new ModpackException('The modpack does not say which Minecraft version it needs.');
        }
        $loader = $loaderVersion = null;
        $loaders = (array) ($data['minecraft']['modLoaders'] ?? []);
        $primary = collect($loaders)->firstWhere('primary', true) ?? ($loaders[0] ?? null);
        if ($primary && preg_match('/^(forge|neoforge|fabric|quilt)-(.+)$/', (string) ($primary['id'] ?? ''), $m)) {
            [$loader, $loaderVersion] = [$m[1], $m[2]];
            // CurseForge writes NeoForge 1.20.1 as "neoforge-1.20.1-47.x"; keep the version only.
            $loaderVersion = preg_replace('/^' . preg_quote($minecraft, '/') . '-/', '', $loaderVersion);
        }

        $entries = [];
        $seen = [];
        foreach ((array) ($data['files'] ?? []) as $ref) {
            $id = (string) ($ref['fileID'] ?? '');
            $f = $files[$id] ?? null;
            $name = (string) ($f['fileName'] ?? "{$id}.jar");
            $entry = [
                'path' => 'mods/' . $name, 'sha1' => $f ? (string) (\ServerSuite\Drivers\CurseForgeDriver::sha1Of($f) ?? '') : '',
                'sha512' => null, 'size' => (int) ($f['fileLength'] ?? 0), 'url' => null, 'action' => 'install', 'reason' => null,
                'curseforge' => ['project_id' => (string) ($ref['projectID'] ?? ''), 'file_id' => $id, 'name' => (string) ($f['displayName'] ?? $name)],
            ];
            $url = (string) ($f['downloadUrl'] ?? '');
            $entries[] = match (true) {
                $f === null => ['action' => 'invalid', 'reason' => 'not found on CurseForge'] + $entry,
                ($ref['required'] ?? true) === false => ['action' => 'client_only', 'reason' => 'optional in the pack'] + $entry,
                ! str_ends_with(strtolower($name), '.jar') => ['action' => 'client_only', 'reason' => 'resource or shader pack (client-side)'] + $entry,
                ! self::safePath('mods/' . $name) || ! preg_match('/^[0-9a-f]{40}$/', $entry['sha1']) => ['action' => 'invalid', 'reason' => 'unsafe file name or no SHA-1'] + $entry,
                $url === '' => ['action' => 'blocked', 'reason' => 'the author does not allow downloads by other apps; get it from curseforge.com by hand'] + $entry,
                ! self::allowedUrl($url, self::CURSEFORGE_HOSTS) => ['action' => 'blocked', 'reason' => 'not hosted on CurseForge\'s CDN'] + $entry,
                default => ['url' => $url] + $entry,
            };
            // Same rule as parse(): one file per path. A repeat (often the same
            // file id twice) is not installed again.
            $last = array_key_last($entries);
            if ($entries[$last]['action'] === 'install') {
                $key = strtolower($entries[$last]['path']);
                if (isset($seen[$key])) {
                    $entries[$last] = ['action' => 'invalid', 'reason' => 'listed twice in the pack'] + $entries[$last];
                }
                $seen[$key] = true;
            }
        }

        $overrides = trim((string) ($data['overrides'] ?? 'overrides'), '/') ?: 'overrides';
        if (! self::safePath($overrides)) {
            throw new ModpackException('The modpack names an unsafe overrides folder.');
        }

        return new self(
            name: mb_substr((string) ($data['name'] ?? 'Modpack'), 0, 120),
            versionId: mb_substr((string) ($data['version'] ?? ''), 0, 120),
            minecraft: $minecraft, loader: $loader, loaderVersion: $loaderVersion,
            files: $entries, sha1: sha1($json), indexFile: 'manifest.json', overrideDirs: [$overrides],
        );
    }

    /** @throws ModpackException */
    public static function parse(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ModpackException('The modpack index (modrinth.index.json) is not valid JSON.');
        }
        if (! is_array($data) || ($data['formatVersion'] ?? null) !== 1) {
            throw new ModpackException('Unsupported modpack format version (expected 1).');
        }
        if (($data['game'] ?? null) !== 'minecraft') {
            throw new ModpackException('This modpack is not for Minecraft.');
        }

        $deps = is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [];
        $minecraft = (string) ($deps['minecraft'] ?? '');
        if ($minecraft === '') {
            throw new ModpackException('The modpack does not say which Minecraft version it needs.');
        }

        $loader = $loaderVersion = null;
        foreach (self::LOADERS as $key => $slug) {
            if (isset($deps[$key])) {
                if ($loader !== null) {
                    throw new ModpackException('The modpack lists more than one mod loader.');
                }
                $loader = $slug;
                $loaderVersion = (string) $deps[$key];
            }
        }

        $files = [];
        $seen = [];
        foreach ((array) ($data['files'] ?? []) as $f) {
            $f = is_array($f) ? $f : [];
            $files[] = $entry = self::classify($f);
            if ($entry['action'] === 'install') {
                $key = strtolower($entry['path']);
                if (isset($seen[$key])) {
                    throw new ModpackException("The modpack lists {$entry['path']} twice.");
                }
                $seen[$key] = true;
            }
        }

        return new self(
            name: mb_substr((string) ($data['name'] ?? 'Modpack'), 0, 120),
            versionId: mb_substr((string) ($data['versionId'] ?? ''), 0, 120),
            minecraft: $minecraft,
            loader: $loader,
            loaderVersion: $loaderVersion,
            files: $files,
            sha1: sha1($json),
        );
    }

    /** @return array<int, array> files that will be downloaded */
    public function installable(): array
    {
        return array_values(array_filter($this->files, fn ($f) => $f['action'] === 'install'));
    }

    /** @return array<int, array> files that won't be, with the reason */
    public function skipped(): array
    {
        return array_values(array_filter($this->files, fn ($f) => $f['action'] !== 'install'));
    }

    public function downloadBytes(): int
    {
        return array_sum(array_column($this->installable(), 'size'));
    }

    /** Server-relative path (no leading slash) that can't leave the server folder. */
    public static function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')
            || preg_match('/[\\\\:\x00-\x1f]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.' || $seg === '..') {
                return false;
            }
        }

        // The install's own staging area is off limits.
        return ! str_starts_with(strtolower($path), '.serversuite/') && strtolower($path) !== '.serversuite';
    }

    /** @param string[]|null $hosts defaults to the .mrpack hosts */
    public static function allowedUrl(string $url, ?array $hosts = null): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), $hosts ?? self::ALLOWED_HOSTS, true)
            && ! isset($parts['user']) && ! isset($parts['pass']);   // no credentials of any kind
    }

    /** @param array<string, mixed> $f */
    private static function classify(array $f): array
    {
        $path = (string) ($f['path'] ?? '');
        $hashes = is_array($f['hashes'] ?? null) ? $f['hashes'] : [];
        $sha1 = strtolower((string) ($hashes['sha1'] ?? ''));
        $entry = [
            'path'   => $path,
            'sha1'   => $sha1,
            'sha512' => isset($hashes['sha512']) ? strtolower((string) $hashes['sha512']) : null,
            'size'   => max(0, (int) ($f['fileSize'] ?? 0)),
            'url'    => null,
            'action' => 'install',
            'reason' => null,
        ];

        if (! self::safePath($path)) {
            return ['action' => 'invalid', 'reason' => 'unsafe file path'] + $entry;
        }
        if (! preg_match('/^[0-9a-f]{40}$/', $sha1)) {
            return ['action' => 'invalid', 'reason' => 'missing SHA-1 hash'] + $entry;
        }
        if ((($f['env'] ?? [])['server'] ?? 'required') === 'unsupported') {
            return ['action' => 'client_only', 'reason' => 'client-side only'] + $entry;
        }
        foreach ((array) ($f['downloads'] ?? []) as $url) {
            if (is_string($url) && self::allowedUrl($url)) {
                return ['url' => $url] + $entry;
            }
        }

        return ['action' => 'blocked', 'reason' => 'no download on an allowed host (' . implode(', ', self::ALLOWED_HOSTS) . ')'] + $entry;
    }
}
