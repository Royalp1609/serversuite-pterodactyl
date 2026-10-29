<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Support\Config\ConfigEditException;
use ServerSuite\Support\Config\ConfigEditor;

/**
 * The Mods tab's Configs view (#21): browse the config folders and edit a
 * file, as text or as the typed form (Pelican #8). Only under the config
 * roots, no "..", at most 512 KiB. The roots are Minecraft's (ROOTS) or the
 * game profile's (#86: Rust's Oxide/Carbon folders, Valheim's BepInEx/config),
 * see roots(), less the files another tab manages (hidden(): Rust's
 * users.cfg and bans.cfg belong to the Players tab). A save writes through Wings and is
 * refused if the file changed on disk since it was opened (servers rewrite
 * configs when they stop). Form saves change only the edited values; the
 * result is read back and checked first (ConfigEditor).
 */
class ConfigFilesService
{
    public const ROOTS = ['/plugins', '/config', '/defaultconfigs', '/kubejs'];
    public const EXTS = ['yml', 'yaml', 'toml', 'json', 'json5', 'properties', 'conf', 'cfg', 'txt', 'ini', 'snbt'];
    public const MAX_BYTES = 524288;
    private const MAX_FIELDS = 600;

    public function __construct(protected DaemonFileClient $files) {}

    /** @return list<string> the config roots for $server's game */
    public static function roots(object $server): array
    {
        $game = app(GameDetectionService::class)->for($server);

        return $game->isMinecraft() ? self::ROOTS : $game->configRoots;
    }

    /** @return list<string> files under $server's roots the Configs tab leaves alone */
    public static function hidden(object $server): array
    {
        return app(GameDetectionService::class)->for($server)->configHidden;
    }

    /**
     * A path under one of the roots, normalised; anything else throws.
     *
     * @param list<string> $roots
     * @param list<string> $hidden
     */
    public static function safe(string $path, array $roots = self::ROOTS, array $hidden = []): string
    {
        $path = '/' . ltrim((string) preg_replace('#/+#', '/', $path), '/');
        // "." segments too: /cfg/./users.cfg would slip past the hidden list.
        if (str_contains($path, '..') || preg_match('#(^|/)\.(/|$)#', $path) || preg_match('/[\x00-\x1F]/', $path)) {
            throw new \InvalidArgumentException('Invalid path.');
        }
        if (in_array(rtrim($path, '/'), $hidden, true)) {
            throw new \InvalidArgumentException('This file is managed in the Players tab.');
        }
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return rtrim($path, '/') ?: '/';
            }
        }
        throw new \InvalidArgumentException('Only files under ' . implode(', ', $roots) . ' can be edited here.');
    }

    /**
     * A folder's folders and config files; null = the roots that exist, merged.
     *
     * @return array{path: ?string, entries: list<array{name: string, path: string, is_file: bool, size: int}>}
     */
    /**
     * @param list<string> $roots
     * @param list<string> $hidden
     */
    public function browse(string $uuid, ?string $path, array $roots = self::ROOTS, array $hidden = []): array
    {
        if ($path === null || $path === '' || $path === '/') {
            $entries = [];
            foreach ($roots as $root) {
                try {
                    $entries = array_merge($entries, $this->entries($uuid, $root, $hidden));
                } catch (\Throwable) {
                    // this loader has no such folder
                }
            }

            return ['path' => null, 'entries' => $entries];
        }
        $path = self::safe($path, $roots, $hidden);

        return ['path' => $path, 'entries' => $this->entries($uuid, $path, $hidden)];
    }

    /**
     * A file's text, its hash (pass it back to save) and the form, if any.
     *
     * @return array{path: string, content: string, hash: string, fields: list<array<string, mixed>>, reason: ?string}
     */
    /**
     * @param list<string> $roots
     * @param list<string> $hidden
     */
    public function open(string $uuid, string $path, array $roots = self::ROOTS, array $hidden = []): array
    {
        $path = self::safe($path, $roots, $hidden);
        $raw = $this->files->readFile($uuid, $path, self::MAX_BYTES)
            ?? throw new \InvalidArgumentException('Could not read the file. It may be gone, or larger than 512 KiB (edit those in the File Manager).');
        $form = ConfigEditor::open($raw, basename($path));
        if (count($form['fields']) > self::MAX_FIELDS) {
            $form = ['fields' => [], 'reason' => 'This file has ' . count($form['fields']) . ' settings, too many for the form, so it opens as text.'];
        }

        return ['path' => $path, 'content' => $raw, 'hash' => sha1($raw), 'fields' => $form['fields'], 'reason' => $form['reason']];
    }

    /**
     * The file's text with form values applied, without saving (for switching
     * from the form to the text editor).
     *
     * @param array<int|string, mixed> $values
     *
     * @throws ConfigEditException|\InvalidArgumentException
     */
    public function render(string $uuid, string $path, string $hash, array $values, array $roots = self::ROOTS, array $hidden = []): string
    {
        return ConfigEditor::save($this->current($uuid, $path, $hash, $roots, $hidden), basename(self::safe($path, $roots, $hidden)), $values)['content'];
    }

    /**
     * Save form values (field id => value) or the whole text.
     *
     * @param array<int|string, mixed>|null $values
     *
     * @return array{changed: list<string>, written: bool}
     *
     * @throws ConfigEditException|\InvalidArgumentException
     */
    public function save(string $uuid, string $path, string $hash, ?array $values, ?string $content, array $roots = self::ROOTS, array $hidden = []): array
    {
        $path = self::safe($path, $roots, $hidden);
        $disk = $this->current($uuid, $path, $hash, $roots, $hidden);
        if ($values !== null) {
            $r = ConfigEditor::save($disk, basename($path), $values);
            ['content' => $new, 'changed' => $changed] = $r;
        } else {
            $new = (string) $content;
            $changed = [];
            if (strlen($new) > self::MAX_BYTES) {
                throw new \InvalidArgumentException('The file would be larger than 512 KiB.');
            }
        }
        if ($new === $disk) {
            return ['changed' => [], 'written' => false];
        }
        $this->files->writeFile($uuid, $path, $new);

        return ['changed' => $changed, 'written' => true];
    }

    /** The file as it is on disk, if it's still what the user opened. */
    private function current(string $uuid, string $path, string $hash, array $roots, array $hidden): string
    {
        $disk = $this->files->readFile($uuid, self::safe($path, $roots, $hidden), self::MAX_BYTES);
        if ($disk === null || ! hash_equals(sha1($disk), $hash)) {
            throw new \InvalidArgumentException('Something else changed ' . $path . ' since you opened it (servers often rewrite configs when they stop). Reopen it and make the change again.');
        }

        return $disk;
    }

    /** @return list<array{name: string, path: string, is_file: bool, size: int}> */
    private function entries(string $uuid, string $dir, array $hidden = []): array
    {
        $dirs = $files = [];
        foreach ($this->files->listDirectory($uuid, $dir) as $e) {
            $name = (string) $e['name'];
            $row = ['name' => $name, 'path' => rtrim($dir, '/') . '/' . $name, 'is_file' => (bool) $e['is_file'], 'size' => (int) ($e['size'] ?? 0)];
            if (in_array($row['path'], $hidden, true)) {
                continue;
            }
            if (! $row['is_file']) {
                $dirs[] = $row;
            } elseif (in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTS, true)) {
                $files[] = $row;
            }
        }
        usort($dirs, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return array_merge($dirs, $files);
    }
}
