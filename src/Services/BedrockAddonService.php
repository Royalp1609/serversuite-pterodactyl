<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Support\BedrockArchive;

/**
 * Bedrock add-ons (#93; Pelican #97): behavior and resource packs on a
 * Bedrock Dedicated Server, which has no mod loader.
 *
 * Archives (.mcpack, .mcaddon) are uploaded to /addons with the panel's file
 * manager, so Wings takes the upload and no panel upload limit applies.
 * Installing one moves it into a hidden folder under /addons first (so it
 * can't change while it's checked), streams it to the panel to be checked
 * (BedrockArchive: paths, symlinks, sizes, manifests), then has Wings unpack
 * it and moves each pack into behavior_packs/ or resource_packs/ as
 * <name>_<first 8 of its UUID>. That folder name is how a pack Server Suite
 * installed is recognised later; BDS ships its own packs in the same
 * folders, and those are left alone.
 *
 * A pack is switched on for the world in worlds/<level-name>/
 * world_behavior_packs.json and world_resource_packs.json, which list
 * {pack_id, version}. A newly switched-on pack goes to the top, as the game's
 * pack screen does; the order is the pack priority. Packs listed there that
 * Server Suite didn't install show up too ("added outside Server Suite"):
 * they can be switched off and moved, not removed. BDS reads all of this when
 * it starts, so every change applies on the next start.
 */
class BedrockAddonService
{
    public const INBOX = '/addons';

    public const DIRS = ['behavior' => '/behavior_packs', 'resource' => '/resource_packs'];

    public const WORLD_FILES = ['behavior' => 'world_behavior_packs.json', 'resource' => 'world_resource_packs.json'];

    public const ARCHIVE = '/\.(mcpack|mcaddon|zip)$/i';

    private const TMP_PREFIX = '.serversuite-tmp-';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Folder names Server Suite gives packs: <name>_<8 hex of the UUID>. */
    private const OURS = '/^[A-Za-z0-9_\-]{0,40}_([0-9a-f]{8})$/';

    public function __construct(protected DaemonFileClient $files) {}

    /** A Bedrock Dedicated Server (not PocketMine, Nukkit or a Java server with Geyser). */
    public static function isBedrock(object $server): bool
    {
        $startup = strtolower((string) ($server->startup ?? ''));
        if (str_contains($startup, 'bedrock_server')) {
            return true;
        }
        $egg = strtolower((string) ($server->egg->name ?? ''));

        return str_contains($egg, 'bedrock') && ! preg_match('/pocket|nukkit|geyser|endstone|levilamina/', $egg . ' ' . $startup);
    }

    /** @return list<array{name: string, size: int, modified_at: ?string}>|null null: there is no /addons folder */
    public function inbox(string $uuid): ?array
    {
        try {
            $entries = $this->files->listDirectory($uuid, self::INBOX);
        } catch (\Throwable) {
            return null;
        }
        $out = [];
        foreach ($entries as $e) {
            if ($e['is_file'] && preg_match(self::ARCHIVE, (string) $e['name'])) {
                $out[] = ['name' => (string) $e['name'], 'size' => (int) $e['size'], 'modified_at' => $e['modified_at'] ?? null];
            }
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    public function createInbox(string $uuid): void
    {
        $this->files->makeDirectory($uuid, self::INBOX);
    }

    /**
     * What's installed and switched on.
     *
     * @return array{world: ?string, world_exists: bool, texturepack_required: bool,
     *     packs: array<string, list<array{uuid: string, version: string, name: string, description: string, folder: ?string, ours: bool,
     *     enabled: bool, position: ?int, needs_beta: bool, missing: list<string>, version_differs: bool}>>}
     */
    public function installed(string $uuid): array
    {
        $world = $this->worldName($uuid);
        $exists = $world !== null && $this->worldExists($uuid, $world);
        $ours = $this->ours($uuid);
        $known = [];
        foreach ($ours as $type => $packs) {
            foreach ($packs as $p) {
                $known[$p['uuid']] = true;
            }
        }

        $packs = [];
        foreach (self::DIRS as $type => $_) {
            $list = $exists ? $this->worldList($uuid, $world, $type) : [];
            $rows = [];
            $onIds = [];
            $position = 0;
            foreach ($list as $entry) {
                $id = strtolower((string) ($entry['pack_id'] ?? ''));
                if (! preg_match(self::UUID, $id)) {
                    continue;   // not a pack id: left in the file as it is, not shown
                }
                $i = $position++;
                $onIds[$id] = true;
                $mine = $ours[$type][$id] ?? null;
                $listed = BedrockArchive::version($entry['version'] ?? null);
                $rows[] = $this->row($mine, $id, $listed, true, $i);
            }
            foreach ($ours[$type] as $id => $mine) {
                if (! isset($onIds[$id])) {
                    $rows[] = $this->row($mine, $id, null, false, null);
                }
            }
            $known += $onIds;
            $packs[$type] = $rows;
        }
        // A dependency is missing when no installed or switched-on pack has its UUID.
        foreach ($packs as &$rows) {
            foreach ($rows as &$r) {
                $r['missing'] = array_values(array_filter($r['missing'], fn ($d) => ! isset($known[$d])));
            }
            unset($r);
        }
        unset($rows);

        return [
            'world' => $world,
            'world_exists' => $exists,
            'texturepack_required' => $this->texturepackRequired($uuid),
            'packs' => $packs,
        ];
    }

    /**
     * Install (or update) the packs in an archive from /addons. Packs already
     * installed at the same version are skipped; an older or newer version of
     * an installed pack replaces it and keeps its place in the world's list.
     *
     * @return array{installed: list<string>, updated: list<string>, skipped: list<string>, notes: list<string>}
     *
     * @throws \RuntimeException with a message for the user
     */
    public function install(string $uuid, string $file, bool $enable = true): array
    {
        if (! preg_match(self::ARCHIVE, $file) || str_contains($file, '/') || str_starts_with($file, '.')) {
            throw new \RuntimeException('Pick an .mcpack or .mcaddon file from the addons folder.');
        }
        if (! in_array($file, array_column($this->inbox($uuid) ?? [], 'name'), true)) {
            throw new \RuntimeException("{$file} is no longer in the addons folder.");
        }

        $tmp = self::TMP_PREFIX . bin2hex(random_bytes(4));
        $tmpDir = self::INBOX . '/' . $tmp;
        $local = tempnam(sys_get_temp_dir(), 'ss-addon') ?: throw new \RuntimeException('Could not make a temporary file to check the archive.');
        $moved = false;
        $done = false;
        try {
            $this->files->makeDirectory($uuid, $tmpDir);
            // Out of the folder users upload to, so it can't change between the check and the unpack.
            $this->files->rename($uuid, self::INBOX, $file, $tmp . '/archive.zip');
            $moved = true;
            $max = max(1, (int) config('serversuite.bedrock.max_archive_mb', 200)) * 1048576;
            if (! $this->files->downloadTo($uuid, $tmpDir . '/archive.zip', $local, $max)) {
                throw new \RuntimeException("Could not read {$file}: it may be larger than " . intdiv($max, 1048576) . ' MB.');
            }
            $packs = BedrockArchive::packs($local, max(1, (int) config('serversuite.bedrock.max_unpacked_mb', 1024)) * 1048576);
            $result = $this->place($uuid, $tmpDir, $packs, $enable);
            $done = true;

            return $result;
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('The install failed: ' . $e->getMessage(), previous: $e);
        } finally {
            @unlink($local);
            if ($moved && ! $done) {
                try {
                    $this->files->rename($uuid, self::INBOX, $tmp . '/archive.zip', $file);   // give the file back
                } catch (\Throwable) {
                }
            }
            try {
                $this->files->deleteFiles($uuid, self::INBOX, [$tmp]);
            } catch (\Throwable) {
                // already moved into place (a pack at the archive's root)
            }
        }
    }

    /** @throws \RuntimeException */
    public function setEnabled(string $uuid, string $type, string $packUuid, bool $on): string
    {
        [$world, $list] = $this->editableList($uuid, $type);
        $packUuid = strtolower($packUuid);
        $without = array_values(array_filter($list, fn ($e) => strtolower((string) ($e['pack_id'] ?? '')) !== $packUuid));
        if (! $on) {
            if (count($without) === count($list)) {
                return 'It was already off.';
            }
            $this->writeWorldList($uuid, $world, $type, $without);

            return 'Switched off. It applies when the server restarts.';
        }
        $mine = $this->ours($uuid)[$type][$packUuid] ?? throw new \RuntimeException('That pack is not installed.');
        if (count($without) !== count($list)) {
            return 'It is already on.';
        }
        $this->writeWorldList($uuid, $world, $type, [['pack_id' => $packUuid, 'version' => $mine['version']], ...$list]);

        return 'Switched on (top of the list). It applies when the server restarts.' . ($mine['needs_beta'] ? ' ' . self::BETA_NOTE : '');
    }

    /** Move a switched-on pack up (-1) or down (+1) the world's list. @throws \RuntimeException */
    public function move(string $uuid, string $type, string $packUuid, int $delta): void
    {
        [$world, $list] = $this->editableList($uuid, $type);
        $ids = array_map(fn ($e) => strtolower((string) ($e['pack_id'] ?? '')), $list);
        $i = array_search(strtolower($packUuid), $ids, true);
        if ($i === false) {
            throw new \RuntimeException('That pack is not switched on.');
        }
        $j = $i + ($delta < 0 ? -1 : 1);
        if ($j < 0 || $j >= count($list)) {
            return;
        }
        [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
        $this->writeWorldList($uuid, $world, $type, $list);
    }

    /** Remove a pack Server Suite installed: its folder and its place in the world's list. @throws \RuntimeException */
    public function remove(string $uuid, string $type, string $packUuid): string
    {
        $packUuid = strtolower($packUuid);
        $mine = $this->ours($uuid)[$type][$packUuid] ?? throw new \RuntimeException('Only packs installed here can be removed here. Switch it off instead.');
        $world = $this->worldName($uuid);
        if ($world !== null && $this->worldExists($uuid, $world)) {
            $list = $this->worldList($uuid, $world, $type);
            $without = array_values(array_filter($list, fn ($e) => strtolower((string) ($e['pack_id'] ?? '')) !== $packUuid));
            if (count($without) !== count($list)) {
                $this->writeWorldList($uuid, $world, $type, $without);
            }
        }
        $this->files->deleteFiles($uuid, self::DIRS[$type], [$mine['folder']]);

        return "Removed {$mine['name']}. It applies when the server restarts.";
    }

    public const BETA_NOTE = 'Its scripts need the world\'s "Beta APIs" experiment: switch it on for the world in game (Settings, Experiments) or in level.dat, or the pack won\'t load.';

    /** texturepack-required in server.properties: players must download the resource packs to join. @throws \RuntimeException */
    public function setTexturepackRequired(string $uuid, bool $on): void
    {
        $props = $this->files->readFile($uuid, '/server.properties', 262144) ?? throw new \RuntimeException('Could not read server.properties.');
        $line = 'texturepack-required=' . ($on ? 'true' : 'false');
        $new = preg_match('/^texturepack-required=.*$/m', $props)
            ? (string) preg_replace('/^texturepack-required=.*$/m', $line, $props, 1)
            : rtrim($props, "\r\n") . (str_contains($props, "\r\n") ? "\r\n" : "\n") . $line . "\n";
        if ($new !== $props) {
            $this->files->writeFile($uuid, '/server.properties', $new);
        }
    }

    // -------------------------------------------------------------------------

    /** Unpack the checked archive in $tmpDir and move each pack into place. */
    private function place(string $uuid, string $tmpDir, array $packs, bool $enable): array
    {
        $ours = $this->ours($uuid);
        $out = ['installed' => [], 'updated' => [], 'skipped' => [], 'notes' => []];
        $todo = [];
        foreach ($packs as $p) {
            $v = BedrockArchive::versionText($p['version']);
            $other = $p['type'] === 'behavior' ? 'resource' : 'behavior';
            if (isset($ours[$other][$p['uuid']])) {
                throw new \RuntimeException("{$p['name']} has the UUID of an installed {$other} pack ({$ours[$other][$p['uuid']]['name']}).");
            }
            $old = $ours[$p['type']][$p['uuid']] ?? null;
            if ($old !== null && $old['version'] === $v) {
                $out['skipped'][] = "{$p['name']} {$v}";
                continue;
            }
            $todo[] = $p + ['old' => $old];
        }
        if ($todo === []) {
            throw new \RuntimeException('Already installed at this version: ' . implode(', ', $out['skipped']) . '.');
        }

        $this->files->decompress($uuid, $tmpDir, 'archive.zip');
        $this->files->deleteFiles($uuid, $tmpDir, ['archive.zip']);
        $nestedDirs = [];
        foreach (array_values(array_unique(array_filter(array_column($todo, 'nested')))) as $i => $nested) {
            $dir = "n{$i}";
            $this->files->makeDirectory($uuid, "{$tmpDir}/{$dir}");
            $this->files->rename($uuid, $tmpDir, $nested, "{$dir}/pack.zip");
            $this->files->decompress($uuid, "{$tmpDir}/{$dir}", 'pack.zip');
            $this->files->deleteFiles($uuid, "{$tmpDir}/{$dir}", ['pack.zip']);
            $nestedDirs[$nested] = $dir;
        }

        $world = $this->worldName($uuid);
        $worldOk = $world !== null && $this->worldExists($uuid, $world);
        foreach ($todo as $p) {
            $src = $tmpDir . ($p['nested'] !== null ? '/' . $nestedDirs[$p['nested']] : '') . ($p['root'] !== '' ? '/' . $p['root'] : '');
            $dest = self::DIRS[$p['type']];
            $folder = self::folderFor($p['name'], $p['uuid']);
            $this->files->makeDirectory($uuid, $dest);
            if ($p['old'] !== null) {
                $this->files->deleteFiles($uuid, $dest, [$p['old']['folder']]);
            }
            $this->files->rename($uuid, '/', ltrim($src, '/'), ltrim($dest, '/') . '/' . $folder);
            $v = BedrockArchive::versionText($p['version']);

            if ($worldOk) {
                $list = $this->worldList($uuid, $world, $p['type']);
                $at = null;
                foreach ($list as $i => $e) {
                    if (strtolower((string) ($e['pack_id'] ?? '')) === $p['uuid']) {
                        $at = $i;
                    }
                }
                if ($at !== null) {
                    $list[$at]['version'] = $p['version'];   // an update keeps its place
                    $this->writeWorldList($uuid, $world, $p['type'], $list);
                } elseif ($enable && $p['old'] === null) {   // an update that was off stays off
                    $this->writeWorldList($uuid, $world, $p['type'], [['pack_id' => $p['uuid'], 'version' => $p['version']], ...$list]);
                }
            }
            $out[$p['old'] !== null ? 'updated' : 'installed'][] = "{$p['name']} {$v}";
            if ($p['needs_beta']) {
                $out['notes'][] = "{$p['name']}: " . self::BETA_NOTE;
            }
        }
        if ($enable && ! $worldOk) {
            $out['notes'][] = 'The world doesn\'t exist yet, so nothing was switched on. Start the server once, then switch the packs on here.';
        }
        $installedIds = array_column($packs, 'uuid');
        $have = $this->ours($uuid);
        foreach ($todo as $p) {
            foreach ($p['dependencies'] as $d) {
                if (! in_array($d['uuid'], $installedIds, true) && ! isset($have['behavior'][$d['uuid']]) && ! isset($have['resource'][$d['uuid']])) {
                    $out['notes'][] = "{$p['name']} needs another pack ({$d['uuid']}) that isn't installed.";
                }
            }
        }

        return $out;
    }

    public static function folderFor(string $name, string $packUuid): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_');

        return substr($slug !== '' ? $slug : 'pack', 0, 40) . '_' . substr(strtolower($packUuid), 0, 8);
    }

    /**
     * The packs Server Suite installed, by type and UUID, from their manifests.
     *
     * @return array<string, array<string, array{uuid: string, version: string, raw_version: mixed, name: string, description: string, folder: string, needs_beta: bool, dependencies: list<string>}>>
     */
    private function ours(string $uuid): array
    {
        $out = [];
        foreach (self::DIRS as $type => $dir) {
            $out[$type] = [];
            try {
                $entries = $this->files->listDirectory($uuid, $dir);
            } catch (\Throwable) {
                continue;
            }
            foreach ($entries as $e) {
                if ($e['is_file'] || ! preg_match(self::OURS, (string) $e['name'], $m)) {
                    continue;
                }
                $raw = $this->files->readFile($uuid, "{$dir}/{$e['name']}/manifest.json", 262144);
                $info = $raw === null ? null : BedrockArchive::manifest($raw);
                if ($info === null || ! str_starts_with($info['uuid'], $m[1])) {
                    continue;   // someone else's folder that happens to look like ours
                }
                $name = $info['name'];
                if (preg_match('/^[\w.]+$/', $name) && str_contains($name, '.')) {
                    $lang = $this->files->readFile($uuid, "{$dir}/{$e['name']}/texts/en_US.lang", 262144);
                    $name = BedrockArchive::langText($lang, $name);
                }
                $out[$type][$info['uuid']] = [
                    'uuid' => $info['uuid'], 'version' => BedrockArchive::versionText($info['version']), 'raw_version' => $info['version'],
                    'name' => BedrockArchive::plain($name) ?: (string) $e['name'], 'description' => BedrockArchive::plain($info['description']),
                    'folder' => (string) $e['name'], 'needs_beta' => $info['needs_beta'], 'dependencies' => array_column($info['dependencies'], 'uuid'),
                ];
            }
        }

        return $out;
    }

    private function row(?array $mine, string $id, array|string|null $listed, bool $enabled, ?int $position): array
    {
        return [
            'uuid' => $id,
            'version' => $mine['version'] ?? ($listed === null ? '?' : BedrockArchive::versionText($listed)),
            'name' => $mine['name'] ?? 'Pack ' . substr($id, 0, 8),
            'description' => $mine['description'] ?? 'Added outside Server Suite.',
            'folder' => $mine['folder'] ?? null,
            'ours' => $mine !== null,
            'enabled' => $enabled,
            'position' => $position,
            'needs_beta' => $mine['needs_beta'] ?? false,
            'missing' => $mine['dependencies'] ?? [],
            'version_differs' => $mine !== null && $listed !== null && BedrockArchive::versionText($listed) !== $mine['version'],
        ];
    }

    /** The world folder name (server.properties level-name), or null if it isn't a safe folder name. */
    public function worldName(string $uuid): ?string
    {
        $props = $this->files->readFile($uuid, '/server.properties', 262144) ?? '';
        $name = preg_match('/^level-name=(.*?)\s*$/m', $props, $m) ? trim($m[1]) : 'Bedrock level';
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || $name === '.' || $name === '..' || preg_match('/[\x00-\x1F]/', $name)) {
            return null;
        }

        return $name;
    }

    private function worldExists(string $uuid, string $world): bool
    {
        try {
            foreach ($this->files->listDirectory($uuid, '/worlds') as $e) {
                if (! $e['is_file'] && $e['name'] === $world) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function texturepackRequired(string $uuid): bool
    {
        $props = $this->files->readFile($uuid, '/server.properties', 262144) ?? '';

        return (bool) preg_match('/^texturepack-required=\s*true\s*$/mi', $props);
    }

    /** @return list<array<string, mixed>> */
    private function worldList(string $uuid, string $world, string $type): array
    {
        $raw = $this->files->readFile($uuid, "/worlds/{$world}/" . self::WORLD_FILES[$type], 1048576);
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $list = BedrockArchive::decode($raw);
        if (! is_array($list) || ! array_is_list($list)) {
            throw new \RuntimeException(self::WORLD_FILES[$type] . ' in the world folder isn\'t a JSON list. Fix or delete it in Files.');
        }

        return array_values(array_filter($list, 'is_array'));
    }

    /** @return array{0: string, 1: list<array<string, mixed>>} @throws \RuntimeException */
    private function editableList(string $uuid, string $type): array
    {
        if (! isset(self::DIRS[$type])) {
            throw new \RuntimeException('Unknown pack type.');
        }
        $world = $this->worldName($uuid);
        if ($world === null || ! $this->worldExists($uuid, $world)) {
            throw new \RuntimeException('The world doesn\'t exist yet. Start the server once so it creates it.');
        }

        return [$world, $this->worldList($uuid, $world, $type)];
    }

    private function writeWorldList(string $uuid, string $world, string $type, array $list): void
    {
        $this->files->writeFile($uuid, "/worlds/{$world}/" . self::WORLD_FILES[$type],
            json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
