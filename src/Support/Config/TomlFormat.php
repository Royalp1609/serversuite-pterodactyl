<?php

namespace ServerSuite\Support\Config;

/**
 * TOML (Forge/NeoForge mod configs, velocity.toml): `[table]` headers and
 * `key = value` lines with strings, numbers, booleans and one-line arrays
 * of one scalar type. Left alone: arrays of tables (`[[x]]`), inline
 * tables, multi-line strings and arrays, dates, hex/octal/binary, inf/nan
 * and numbers with `_`. A line that isn't valid in that subset makes the
 * whole file raw-only.
 */
class TomlFormat extends Format
{
    private const BARE = '[A-Za-z0-9_-]+';

    public static function scan(string $raw, string $variant): ?array
    {
        $slots = [];
        $help = [];
        $table = [];
        $arrayTable = false;
        $skip = null;         // closing delimiter of a multi-line string, or ']' for an array
        $depth = 0;

        foreach (self::lines($raw) as $line) {
            $text = $line['text'];
            $t = trim($text);

            if ($skip === ']') {
                $depth += self::bracketDelta($t);
                if ($depth <= 0) {
                    $skip = null;
                }
                continue;
            }
            if ($skip !== null) {
                if (str_contains($t, $skip)) {
                    $skip = null;
                }
                continue;
            }

            if ($t === '') {
                $help = [];
                continue;
            }
            if ($t[0] === '#') {
                $help[] = self::commentText($t);
                continue;
            }
            if (preg_match('/^\[\[(.+)\]\]\s*(#.*)?$/', $t)) {
                $arrayTable = true;
                $help = [];
                continue;
            }
            if (preg_match('/^\[([^\[\]]+)\]\s*(#.*)?$/', $t, $m)) {
                $keys = self::keyPath(trim($m[1]));
                if ($keys === null) {
                    return null;
                }
                $table = $keys;
                $arrayTable = false;
                $help = [];
                continue;
            }

            $eq = self::keyEnd($text);
            if ($eq === null) {
                return null;
            }
            $keys = self::keyPath(trim(substr($text, 0, $eq)));
            if ($keys === null) {
                return null;
            }
            $path = array_merge($table, $keys);
            $vOff = $eq + 1 + (strlen(substr($text, $eq + 1)) - strlen(ltrim(substr($text, $eq + 1))));
            $v = substr($text, $vOff);
            $vStart = $line['start'] + $vOff;
            $note = ['help' => self::helpText($help), 'editable' => ! $arrayTable];
            $help = [];

            if (str_starts_with($v, '"""') || str_starts_with($v, "'''")) {
                $d = substr($v, 0, 3);
                if (! str_contains(substr($v, 3), $d)) {
                    $skip = $d;
                }
                $slots[] = self::slot($path, 'string', null, $vStart, $vStart, ['editable' => false] + $note);
                continue;
            }
            if (str_starts_with($v, '[')) {
                $end = self::arrayEnd($v);
                if ($end === null) {
                    $skip = ']';
                    $depth = self::bracketDelta($v);
                    $slots[] = self::slot($path, 'list', null, $vStart, $vStart, ['editable' => false] + $note);
                    continue;
                }
                if (! self::restOk(substr($v, $end))) {
                    return null;
                }
                $items = self::arrayItems(substr($v, 0, $end));
                $type = $items === null ? null : self::itemType($items);
                $slots[] = self::slot($path, 'list', $items, $vStart, $vStart + $end, [
                    'item' => $type ?? 'string',
                    'style' => ['pad' => (bool) preg_match('/^\[\s/', $v)],
                ] + ($type === null ? ['editable' => false] : []) + $note);
                continue;
            }
            if (str_starts_with($v, '{')) {
                $slots[] = self::slot($path, 'string', null, $vStart, $vStart, ['editable' => false] + $note);
                continue;
            }

            $tok = self::scalar($v, 0);
            if ($tok === null) {
                return null;
            }
            [$type, $value, $len, $style] = $tok;
            if (! self::restOk(substr($v, $len))) {
                return null;
            }
            $slots[] = self::slot($path, $type ?? 'string', $value, $vStart, $vStart + $len, ['style' => $style]
                + ($type === null ? ['editable' => false] : []) + $note);
        }

        return self::markDuplicates($slots);
    }

    public static function encode(array $slot, mixed $value): string
    {
        if ($slot['type'] === 'list') {
            $items = array_map(fn ($v) => self::scalarText($slot['item'], $v, '"'), $value);
            $pad = ($slot['style']['pad'] ?? false) && $items !== [] ? ' ' : '';

            return '[' . $pad . implode(', ', $items) . $pad . ']';
        }

        return self::scalarText($slot['type'], $value, $slot['style']['quote'] ?? '"');
    }

    // -------------------------------------------------------------------------

    private static function scalarText(string $type, mixed $value, string $quote): string
    {
        return match ($type) {
            'bool'  => $value ? 'true' : 'false',
            'int'   => (string) $value,
            'float' => self::floatText((float) $value),
            default => self::stringText((string) $value, $quote),
        };
    }

    private static function stringText(string $s, string $quote): string
    {
        if ($quote === "'" && ! str_contains($s, "'") && ! preg_match('/[\x00-\x1F\x7F]/', $s)) {
            return "'" . $s . "'";
        }
        $out = '';
        foreach (mb_str_split($s) as $ch) {
            $out .= match ($ch) {
                '"' => '\\"', '\\' => '\\\\', "\n" => '\\n', "\t" => '\\t', "\r" => '\\r', "\f" => '\\f', "\x08" => '\\b',
                default => (strlen($ch) === 1 && (ord($ch) < 0x20 || ord($ch) === 0x7F)) ? sprintf('\\u%04X', ord($ch)) : $ch,
            };
        }

        return '"' . $out . '"';
    }

    /**
     * One scalar at $pos. Returns [type|null (not editable), value, length, style], or
     * null when it isn't valid TOML.
     *
     * @return array{0: ?string, 1: mixed, 2: int, 3: array<string, mixed>}|null
     */
    private static function scalar(string $s, int $pos): ?array
    {
        $rest = substr($s, $pos);
        if ($rest === '') {
            return null;
        }
        $c = $rest[0];
        if ($c === '"') {
            $end = self::quoteEnd($rest, 0, true);
            $val = $end === null ? null : self::basicString(substr($rest, 1, $end - 2));

            return $val === null ? null : ['string', $val, $end, ['quote' => '"']];
        }
        if ($c === "'") {
            $end = strpos($rest, "'", 1);

            return $end === false ? null : ['string', substr($rest, 1, $end - 1), $end + 1, ['quote' => "'"]];
        }
        if (preg_match('/^(true|false)(?![A-Za-z0-9_-])/', $rest, $m)) {
            return ['bool', $m[1] === 'true', strlen($m[1]), []];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}([Tt ]\d{2}:\d{2}(:\d{2})?(\.\d+)?)?([Zz]|[+-]\d{2}:\d{2})?|^\d{2}:\d{2}(:\d{2})?(\.\d+)?/', $rest, $m)) {
            return [null, $m[0], strlen($m[0]), []];
        }
        if (preg_match('/^[+-]?(inf|nan)|^0[xob][0-9A-Fa-f_]+/', $rest, $m)) {
            return [null, $m[0], strlen($m[0]), []];
        }
        if (preg_match('/^[+-]?(0|[1-9][\d_]*)(\.\d[\d_]*)?([eE][+-]?\d[\d_]*)?/', $rest, $m)) {
            $text = $m[0];
            if (str_contains($text, '_')) {
                return [null, $text, strlen($text), []];
            }
            $isFloat = isset($m[2]) && $m[2] !== '' || isset($m[3]) && $m[3] !== '';

            return $isFloat ? ['float', (float) $text, strlen($text), []] : ['int', (int) $text, strlen($text), []];
        }

        return null;
    }

    private static function basicString(string $body): ?string
    {
        $bad = false;
        $out = preg_replace_callback('/\\\\(u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/', function ($m) use (&$bad) {
            $e = $m[1];
            if ($e[0] === 'u' || $e[0] === 'U') {
                $cp = hexdec(substr($e, 1));

                return $cp <= 0x10FFFF ? mb_chr((int) $cp, 'UTF-8') : '';
            }

            return match ($e) {
                'b' => "\x08", 't' => "\t", 'n' => "\n", 'f' => "\f", 'r' => "\r", '"' => '"', '\\' => '\\',
                default => (function () use (&$bad) { $bad = true; return ''; })(),
            };
        }, $body);

        return $bad || $out === null ? null : $out;
    }

    /** @return list<mixed>|null scalars of a one-line array, or null if it holds anything else */
    private static function arrayItems(string $arr): ?array
    {
        $inner = substr($arr, 1, -1);
        $items = [];
        $pos = 0;
        $n = strlen($inner);
        while (true) {
            while ($pos < $n && ctype_space($inner[$pos])) {
                $pos++;
            }
            if ($pos >= $n) {
                break;
            }
            $tok = self::scalar($inner, $pos);
            if ($tok === null || $tok[0] === null) {
                return null;
            }
            $items[] = $tok[1];
            $pos += $tok[2];
            while ($pos < $n && ctype_space($inner[$pos])) {
                $pos++;
            }
            if ($pos >= $n) {
                break;
            }
            if ($inner[$pos] !== ',') {
                return null;
            }
            $pos++;
        }

        return $items;
    }

    /** @param list<mixed> $items */
    private static function itemType(array $items): ?string
    {
        if ($items === []) {
            return null;
        }
        $types = array_unique(array_map(fn ($v) => match (true) {
            is_bool($v) => 'bool', is_int($v) => 'int', is_float($v) => 'float', default => 'string',
        }, $items));

        return count($types) === 1 ? $types[0] : null;
    }

    /** @return list<string>|null */
    private static function keyPath(string $key): ?array
    {
        $parts = [];
        $pos = 0;
        $n = strlen($key);
        while ($pos < $n) {
            while ($pos < $n && ($key[$pos] === ' ' || $key[$pos] === "\t")) {
                $pos++;
            }
            if ($pos < $n && ($key[$pos] === '"' || $key[$pos] === "'")) {
                $end = $key[$pos] === '"' ? self::quoteEnd($key, $pos, true) : (($e = strpos($key, "'", $pos + 1)) === false ? null : $e + 1);
                if ($end === null) {
                    return null;
                }
                $body = substr($key, $pos + 1, $end - $pos - 2);
                $parts[] = $key[$pos] === '"' ? (self::basicString($body) ?? $body) : $body;
                $pos = $end;
            } elseif (preg_match('/\G' . self::BARE . '/', $key, $m, 0, $pos)) {
                $parts[] = $m[0];
                $pos += strlen($m[0]);
            } else {
                return null;
            }
            while ($pos < $n && ($key[$pos] === ' ' || $key[$pos] === "\t")) {
                $pos++;
            }
            if ($pos < $n) {
                if ($key[$pos] !== '.') {
                    return null;
                }
                $pos++;
            }
        }

        return $parts === [] ? null : $parts;
    }

    /** Offset of the `=` after a (possibly quoted) key, or null. */
    private static function keyEnd(string $line): ?int
    {
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($c === '"') {
                $e = self::quoteEnd($line, $i, true);
                if ($e === null) {
                    return null;
                }
                $i = $e - 1;
            } elseif ($c === "'") {
                $e = strpos($line, "'", $i + 1);
                if ($e === false) {
                    return null;
                }
                $i = $e;
            } elseif ($c === '=') {
                return $i;
            } elseif ($c === '#') {
                return null;
            }
        }

        return null;
    }

    /** Where a one-line array closes, or null when it continues on later lines. */
    private static function arrayEnd(string $s): ?int
    {
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '"') {
                $e = self::quoteEnd($s, $i, true);
                if ($e === null) {
                    return null;
                }
                $i = $e - 1;
            } elseif ($c === "'") {
                $e = strpos($s, "'", $i + 1);
                if ($e === false) {
                    return null;
                }
                $i = $e;
            } elseif ($c === '#') {
                return null;
            } elseif ($c === '[') {
                $depth++;
            } elseif ($c === ']' && --$depth === 0) {
                return $i + 1;
            }
        }

        return null;
    }

    private static function bracketDelta(string $s): int
    {
        $s = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'|#.*$/', '', $s);

        return substr_count($s, '[') - substr_count($s, ']');
    }

    private static function restOk(string $rest): bool
    {
        return preg_match('/^\s*(#.*)?$/', $rest) === 1;
    }
}
