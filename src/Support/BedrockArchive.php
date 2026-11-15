<?php

namespace ServerSuite\Support;

/**
 * Reads a Bedrock add-on archive (#97): an .mcpack (one pack) or an .mcaddon
 * (several packs, as folders or as .mcpack files inside). Pure: a local zip
 * in, the packs it holds out. Nothing is unpacked here; Wings does that once
 * the archive has passed these checks, so they are what keeps an archive
 * from writing outside its pack folder:
 *  - no absolute paths, backslashes, "." or ".." segments or control
 *    characters in entry names (zip slip), no symlinks;
 *  - at most MAX_ENTRIES entries and $maxUnpacked bytes unpacked (zip bombs),
 *    one level of nested .mcpack;
 *  - every pack has a manifest.json with a UUID, a version and one kind of
 *    module: resources (a resource pack) or data/script (a behavior pack).
 *    Skin packs and world templates aren't server content and are refused.
 * A pack whose scripts use a beta @minecraft/ module needs the world's
 * "Beta APIs" experiment, which lives in level.dat: reported, not set.
 */
final class BedrockArchive
{
    public const MAX_ENTRIES = 20000;

    /** The old GameTest framework module: beta only. */
    private const GAMETEST_UUID = '6f4b6893-1bb6-42fd-b458-7fa3d0c89616';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @return list<array{type: 'behavior'|'resource', uuid: string, version: list<int>|string, name: string, description: string,
     *     root: string, nested: ?string, needs_beta: bool, dependencies: list<array{uuid: string, version: mixed}>, min_engine: ?string}>
     *
     * @throws \RuntimeException with a message for the user
     */
    public static function packs(string $zipPath, int $maxUnpacked = 536870912): array
    {
        $tmp = [];
        $budget = $maxUnpacked;   // shared by the archive and every pack inside it (item 105)
        try {
            return self::scan($zipPath, $budget, null, $tmp, $maxUnpacked);
        } finally {
            foreach ($tmp as $f) {
                @unlink($f);
            }
        }
    }

    /**
     * @param int          $budget bytes still allowed to unpack, across nested packs too
     * @param list<string> $tmp    temp files to clean up
     */
    private static function scan(string $zipPath, int &$budget, ?string $nested, array &$tmp, int $limit): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException(($nested ?? 'The file') . ' is not a valid .mcpack/.mcaddon (not a zip archive).');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new \RuntimeException('The archive has more than ' . self::MAX_ENTRIES . ' files.');
            }
            $names = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $st = $zip->statIndex($i);
                $name = (string) ($st['name'] ?? '');
                self::checkName($name);
                $zip->getExternalAttributesIndex($i, $os, $attr);
                if ($os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException("The archive contains a symlink ({$name}), which isn't allowed.");
                }
                $total += (int) ($st['size'] ?? 0);
                if ($total > $budget) {
                    throw new \RuntimeException('The archive unpacks to more than ' . intdiv($limit, 1048576) . ' MB.');
                }
                $names[$i] = $name;
            }
            $budget -= $total;

            // A pack is the folder holding a manifest.json; only the outermost counts.
            $roots = [];
            foreach ($names as $name) {
                if (basename($name) === 'manifest.json') {
                    $roots[] = dirname($name) === '.' ? '' : dirname($name);
                }
            }
            sort($roots);
            $outer = [];
            foreach ($roots as $r) {
                foreach ($outer as $o) {
                    if ($o === '' || str_starts_with($r, $o . '/')) {
                        continue 2;
                    }
                }
                $outer[] = $r;
            }

            $packs = [];
            foreach ($outer as $root) {
                $packs[] = self::pack($zip, $root, $nested);
            }

            // .mcpack files inside an .mcaddon (one level).
            foreach ($names as $i => $name) {
                if (! preg_match('/\.(mcpack|zip)$/i', $name)) {
                    continue;
                }
                foreach ($outer as $o) {
                    if ($o === '' || str_starts_with($name, $o . '/')) {
                        continue 2;   // an archive inside a pack is the pack's business
                    }
                }
                if ($nested !== null) {
                    throw new \RuntimeException("{$nested} contains another archive ({$name}); only one level of .mcpack is supported.");
                }
                $file = tempnam(sys_get_temp_dir(), 'ss-mcpack') ?: throw new \RuntimeException('Could not make a temporary file to read the archive.');
                $tmp[] = $file;
                $in = $zip->getStream($name);
                $out = fopen($file, 'wb');
                if ($in === false || $out === false) {
                    throw new \RuntimeException("Could not read {$name} from the archive.");
                }
                // No more than its stated size (already counted above): a
                // lying header can't fill the disk while it's copied out.
                $declared = (int) ($zip->statIndex($i)['size'] ?? 0);
                $copied = stream_copy_to_stream($in, $out, $declared + 1);
                fclose($in);
                fclose($out);
                if ($copied === false || $copied > $declared) {
                    throw new \RuntimeException("{$name} is larger than the archive says.");
                }
                array_push($packs, ...self::scan($file, $budget, $name, $tmp, $limit));
            }

            if ($packs === [] && $nested === null) {
                throw new \RuntimeException('No pack found: an add-on needs a manifest.json in each pack.');
            }

            return $packs;
        } finally {
            $zip->close();
        }
    }

    /** @throws \RuntimeException */
    private static function checkName(string $name): void
    {
        $bad = $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || preg_match('#(^|/)\.{1,2}(/|$)#', $name)
            || preg_match('/^[A-Za-z]:/', $name);
        if ($bad) {
            throw new \RuntimeException('The archive has an unsafe file path (' . mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '?', $name), 0, 120) . '), so it was not installed.');
        }
    }

    /** @throws \RuntimeException */
    private static function pack(\ZipArchive $zip, string $root, ?string $nested): array
    {
        $prefix = $root === '' ? '' : $root . '/';
        $where = trim(($nested ?? '') . ' ' . ($root === '' ? '' : $root . '/')) . 'manifest.json';
        $raw = $zip->getFromName($prefix . 'manifest.json');
        $m = $raw === false ? null : self::decode($raw);
        if (! is_array($m) || ! is_array($m['header'] ?? null)) {
            throw new \RuntimeException($m === null && $raw !== false ? "{$where} isn't valid JSON (" . json_last_error_msg() . ').' : "{$where} has no header.");
        }
        $kinds = [];
        foreach ((array) ($m['modules'] ?? []) as $mod) {
            $kinds[] = strtolower((string) (is_array($mod) ? ($mod['type'] ?? '') : ''));
        }
        if (in_array('skin_pack', $kinds, true)) {
            throw new \RuntimeException('This is a skin pack. Skins are chosen by each player in game; they can\'t be installed on a server.');
        }
        if (in_array('world_template', $kinds, true)) {
            throw new \RuntimeException('This is a world template (.mctemplate), not an add-on. World imports aren\'t supported yet.');
        }
        $info = self::manifest($raw);
        if ($info === null) {
            throw new \RuntimeException("{$where} has no valid header.uuid and header.version.");
        }
        if ($info['type'] === null) {
            throw new \RuntimeException("{$where} must have either a resources module or a data/script module.");
        }
        $lang = $zip->getFromName($prefix . 'texts/en_US.lang');
        $lang = $lang === false ? null : $lang;

        return [
            'type' => $info['type'],
            'uuid' => $info['uuid'],
            'version' => $info['version'],
            'name' => self::plain(self::langText($lang, $info['name'])) ?: ($root !== '' ? basename($root) : pathinfo((string) $nested, PATHINFO_FILENAME)) ?: 'Unnamed pack',
            'description' => self::plain(self::langText($lang, $info['description'])),
            'root' => $root,
            'nested' => $nested,
            'needs_beta' => $info['needs_beta'],
            'dependencies' => $info['dependencies'],
            'min_engine' => $info['min_engine'],
        ];
    }

    /**
     * The parts of a manifest.json Server Suite uses; null when it has no
     * valid UUID and version. type is null unless the modules are all of
     * one kind (resources, or data/script).
     *
     * @return array{uuid: string, version: list<int>|string, name: string, description: string, type: 'behavior'|'resource'|null,
     *     needs_beta: bool, dependencies: list<array{uuid: string, version: mixed}>, min_engine: ?string}|null
     */
    public static function manifest(string $raw): ?array
    {
        $m = self::decode($raw);
        $h = is_array($m) ? ($m['header'] ?? null) : null;
        if (! is_array($h)) {
            return null;
        }
        $uuid = strtolower((string) ($h['uuid'] ?? ''));
        $version = self::version($h['version'] ?? null);
        if (! preg_match(self::UUID, $uuid) || $version === null) {
            return null;
        }
        $kinds = [];
        foreach ((array) ($m['modules'] ?? []) as $mod) {
            $kinds[] = strtolower((string) (is_array($mod) ? ($mod['type'] ?? '') : ''));
        }
        $resource = in_array('resources', $kinds, true);
        $behavior = (bool) array_intersect($kinds, ['data', 'script', 'javascript', 'client_data']);

        $deps = [];
        $beta = false;
        foreach ((array) ($m['dependencies'] ?? []) as $d) {
            if (! is_array($d)) {
                continue;
            }
            $module = (string) ($d['module_name'] ?? '');
            $v = $d['version'] ?? null;
            $id = strtolower((string) ($d['uuid'] ?? ''));
            if ((str_starts_with($module, '@minecraft/') && is_string($v) && str_contains(strtolower($v), 'beta')) || $id === self::GAMETEST_UUID) {
                $beta = true;
            }
            if (preg_match(self::UUID, $id) && $id !== self::GAMETEST_UUID) {
                $deps[] = ['uuid' => $id, 'version' => $v];
            }
        }
        $minEngine = self::version($h['min_engine_version'] ?? null);

        return [
            'uuid' => $uuid,
            'version' => $version,
            'name' => trim((string) ($h['name'] ?? '')),
            'description' => trim((string) ($h['description'] ?? '')),
            'type' => $resource === $behavior ? null : ($resource ? 'resource' : 'behavior'),
            'needs_beta' => $beta,
            'dependencies' => $deps,
            'min_engine' => $minEngine === null ? null : self::versionText($minEngine),
        ];
    }

    /** [1, 2, 3] or "1.2.3" (manifest format 2), as found; null if neither. */
    public static function version(mixed $v): array|string|null
    {
        if (is_array($v) && count($v) === 3 && array_filter($v, fn ($n) => is_int($n) && $n >= 0) === $v) {
            return array_values($v);
        }
        if (is_string($v) && preg_match('/^\d+\.\d+\.\d+(?:[-+][\w.\-]+)?$/', $v)) {
            return $v;
        }

        return null;
    }

    public static function versionText(array|string $v): string
    {
        return is_array($v) ? implode('.', $v) : $v;
    }

    /** A name or description that is a key like "pack.name": its text from the pack's texts/en_US.lang. */
    public static function langText(?string $lang, string $s): string
    {
        $s = trim($s);
        if ($lang !== null && $s !== '' && preg_match('/^[\w.]+$/', $s) && str_contains($s, '.')
            && preg_match('/^' . preg_quote($s, '/') . '=(.*?)\s*(?:##.*)?$/m', $lang, $m)) {
            return trim($m[1]);
        }

        return $s;
    }

    /** Text for the panel: no Bedrock formatting codes (§a) or control characters, at most 300 characters. */
    public static function plain(string $s): string
    {
        $s = (string) preg_replace('/§./u', '', $s);

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s)), 0, 300);
    }

    /**
     * Decode a manifest or pack list the way the game reads it: as JSON, or
     * failing that with // and block comments and trailing commas taken
     * out (the game accepts them, json_decode doesn't). Null if neither works.
     */
    public static function decode(string $json): mixed
    {
        $json = (string) preg_replace('/^\xEF\xBB\xBF/', '', $json);
        $out = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $out;
        }
        $out = json_decode(self::stripComments($json), true);

        return json_last_error() === JSON_ERROR_NONE ? $out : null;
    }

    /**
     * $json without comments and trailing commas. A plain scan, not a regex:
     * a regex over a long string value (a pack description of a few KB)
     * ran out of PCRE's JIT stack and returned nothing.
     */
    public static function stripComments(string $json): string
    {
        $out = '';
        $n = strlen($json);
        for ($i = 0; $i < $n; $i++) {
            $c = $json[$i];
            if ($c === '"') {
                // A whole string, escapes included.
                $j = $i + 1;
                while ($j < $n && $json[$j] !== '"') {
                    $j += $json[$j] === '\\' ? 2 : 1;
                }
                $out .= substr($json, $i, $j - $i + 1);
                $i = $j;
            } elseif ($c === '/' && ($json[$i + 1] ?? '') === '/') {
                $nl = strpos($json, "\n", $i);
                $i = $nl === false ? $n : $nl - 1;
            } elseif ($c === '/' && ($json[$i + 1] ?? '') === '*') {
                $end = strpos($json, '*/', $i + 2);
                $i = $end === false ? $n : $end + 1;
            } elseif ($c === ']' || $c === '}') {
                $out = rtrim($out);
                if (str_ends_with($out, ',')) {
                    $out = substr($out, 0, -1);
                }
                $out .= $c;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }
}
