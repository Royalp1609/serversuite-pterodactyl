<?php

namespace ServerSuite\Support\Config;

/**
 * `key=value` files, read line by line. Variants:
 *  - properties: Java .properties (`=` or `:`, `#`/`!` comments, backslash
 *    escapes). server.properties and most Bukkit-era plugins.
 *  - ini:  `[section]` headers, `;` or `#` comments.
 *  - cfg:  Forge's old format: `category {` ... `}` blocks and typed keys
 *    (`B:enabled=true`, `I:`, `D:`, `S:`); `S:list <` ... `>` lists are
 *    left alone. `[section]` headers are accepted too.
 *  - conf: HOCON-style: `name {` blocks, `=` or `:`, `#` and `//`
 *    comments, quoted strings. Arrays are left alone.
 * A line the scanner doesn't recognise is kept as it is and not offered.
 */
class KeyValueFormat extends Format
{
    public static function scan(string $raw, string $variant): ?array
    {
        $props = $variant === 'properties';
        $braces = in_array($variant, ['cfg', 'conf'], true);
        $sections = in_array($variant, ['ini', 'cfg'], true);
        $conf = $variant === 'conf';

        $slots = [];
        $help = [];
        $section = [];
        $stack = [];
        $skipUntil = null;      // '>' for a Forge list, ']' for a HOCON array
        $depth = 0;
        $continued = false;     // a .properties value continued on the next line

        foreach (self::lines($raw) as $line) {
            $text = $line['text'];
            $t = trim($text);

            if ($continued) {
                $continued = self::continues($text);
                continue;
            }
            if ($skipUntil === '>') {
                if ($t === '>') {
                    $skipUntil = null;
                }
                continue;
            }
            if ($skipUntil === ']') {
                $depth += substr_count($t, '[') - substr_count($t, ']');
                if ($depth <= 0) {
                    $skipUntil = null;
                }
                continue;
            }

            if ($t === '') {
                $help = [];
                continue;
            }
            if ($t[0] === '#' || ($props && $t[0] === '!') || (! $props && $t[0] === ';') || ($conf && str_starts_with($t, '//'))) {
                $help[] = self::commentText($t);
                continue;
            }
            if ($braces && ($t === '}' || $t === '},')) {
                array_pop($stack);
                $help = [];
                continue;
            }
            if ($sections && preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
                $section = [trim($m[1])];
                $stack = [];
                $help = [];
                continue;
            }
            if ($braces && preg_match('/^(?:"([^"]+)"|([^\s"{=:]+))\s*(?:[=:]\s*)?\{$/', $t, $m)) {
                $stack[] = $m[1] !== '' ? $m[1] : $m[2];
                $help = [];
                continue;
            }

            $re = $props
                ? '/^(\s*)((?:[^=:\\\\]|\\\\.)+?)\s*[=:]\s*(.*)$/'
                : '/^(\s*)(?:([BIDS]):)?("(?:[^"\\\\]|\\\\.)*"|[^=:\s"][^=:]*?)\s*[=:]\s*(.*)$/';
            if (! preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $help = [];
                continue;
            }
            $prefix = $props ? '' : $m[2][0];
            $key = $props ? self::unescape(trim($m[2][0])) : $m[3][0];
            $vRaw = $props ? $m[3][0] : $m[4][0];
            $vStart = $line['start'] + ($props ? $m[3][1] : $m[4][1]);
            if (! $props && str_starts_with($key, '"')) {
                $key = (string) json_decode($key);
            }
            $path = array_merge($section, $stack, [$key]);
            $note = ['help' => self::helpText($help)];
            $help = [];

            if ($props && self::continues($text)) {
                $continued = true;
                $slots[] = self::slot($path, 'string', null, $vStart, $vStart, $note + ['editable' => false]);
                continue;
            }
            $v = rtrim($vRaw);
            if ($variant === 'cfg' && $v === '<') {
                $skipUntil = '>';
                continue;
            }
            if ($braces && $v === '{') {
                $stack[] = $key;
                continue;
            }
            if ($conf && str_starts_with($v, '[')) {
                $depth = substr_count($v, '[') - substr_count($v, ']');
                if ($depth > 0) {
                    $skipUntil = ']';
                }
                $slots[] = self::slot($path, 'list', null, $vStart, $vStart + strlen($v), $note + ['editable' => false]);
                continue;
            }

            $slot = self::value($v, $vStart, $prefix, $variant);
            if ($slot === null) {
                $slots[] = self::slot($path, 'string', null, $vStart, $vStart + strlen($v), $note + ['editable' => false]);
                continue;
            }
            $slots[] = $note + ['path' => $path] + $slot;
        }

        return self::markDuplicates($slots);
    }

    /** @return array<string, mixed>|null the slot without path/help, or null when not editable */
    private static function value(string $v, int $start, string $prefix, string $variant): ?array
    {
        $conf = $variant === 'conf';

        if (! $conf && $variant !== 'properties' && str_starts_with($v, '"')) {
            $end = self::quoteEnd($v, 0, true);

            return $end === strlen($v)
                ? self::slot([], 'string', (string) json_decode($v), $start, $start + $end, ['style' => ['quote' => '"']])
                : null;
        }
        if ($conf) {
            if (str_starts_with($v, '"')) {
                $end = self::quoteEnd($v, 0, true);
                if ($end === null || ! preg_match('/^\s*(,|(#|\/\/).*)?$/', substr($v, $end))) {
                    return null;
                }
                $decoded = json_decode(substr($v, 0, $end));

                return is_string($decoded)
                    ? self::slot([], 'string', $decoded, $start, $start + $end, ['style' => ['quote' => '"']])
                    : null;
            }
            // Unquoted: an inline comment or trailing comma ends the value.
            $v = rtrim((string) preg_replace('/\s*,?\s*(\s#|\s\/\/).*$|\s*,$/', '', $v));
            if (str_contains($v, '#') || str_contains($v, '//') || preg_match('/[{}\[\]]/', $v)) {
                return null;
            }
        }

        $len = strlen($v);
        $text = $variant === 'properties' ? self::unescape($v) : $v;
        $bool = preg_match('/^(true|false)$/i', $text) === 1;
        $int = preg_match('/^-?(0|[1-9]\d{0,17})$/', $text) === 1;
        $float = preg_match('/^-?\d+\.\d+$/', $text) === 1;

        $type = match ($prefix) {
            'B' => $bool ? 'bool' : null,
            'I' => $int ? 'int' : null,
            'D' => ($float || $int) ? 'float' : null,
            'S' => 'string',
            default => $bool ? 'bool' : ($int ? 'int' : ($float ? 'float' : 'string')),
        };
        if ($type === null) {
            return null;
        }

        $value = match ($type) {
            'bool'  => strtolower($text) === 'true',
            'int'   => (int) $text,
            'float' => (float) $text,
            default => $text,
        };
        $style = ['props' => $variant === 'properties', 'case' => self::caseOf($text), 'conf' => $conf];

        return self::slot([], $type, $value, $start, $start + $len, ['style' => $style]);
    }

    public static function encode(array $slot, mixed $value): string
    {
        $style = $slot['style'];

        return match ($slot['type']) {
            'bool'  => match ($style['case'] ?? 'lower') {
                'upper' => $value ? 'TRUE' : 'FALSE',
                'title' => $value ? 'True' : 'False',
                default => $value ? 'true' : 'false',
            },
            'int'   => (string) $value,
            'float' => self::floatText((float) $value),
            default => self::encodeString((string) $value, $style),
        };
    }

    /** @param array<string, mixed> $style */
    private static function encodeString(string $s, array $style): string
    {
        if (($style['quote'] ?? null) === '"') {
            return (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($style['props'] ?? false) {
            $out = str_replace(['\\', "\t"], ['\\\\', '\\t'], $s);
            if (str_starts_with($out, ' ')) {
                $out = '\\' . $out;   // Java drops leading spaces from values
            }

            // Non-ASCII as \u escapes: read the same as ISO-8859-1 or UTF-8.
            return (string) preg_replace_callback('/[^\x00-\x7E]/u', function ($m) {
                $units = unpack('n*', (string) mb_convert_encoding($m[0], 'UTF-16BE', 'UTF-8')) ?: [];

                return implode('', array_map(fn ($u) => sprintf('\\u%04x', $u), $units));
            }, $out);
        }
        if (($style['conf'] ?? false) && ($s === '' || preg_match('/[#,{}\[\]"]|\/\/|^\s|\s$|^(true|false|-?\d+(\.\d+)?)$/i', $s))) {
            return (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $s;
    }

    /**
     * Values here are text to the program reading them (a seed of 12345 is
     * still a seed), so a re-read is compared as text: "12345" saved into a
     * text setting reads back as a number, and that's the same setting.
     */
    public static function normalize(array $slot): mixed
    {
        return match ($slot['type']) {
            'bool'  => $slot['value'] ? 'true' : 'false',
            'float' => is_float($slot['value']) ? self::floatText($slot['value']) : (string) $slot['value'],
            default => is_scalar($slot['value']) ? (string) $slot['value'] : $slot['value'],
        };
    }

    /** The case style of a boolean, kept on save (some INI readers want True). */
    private static function caseOf(string $text): string
    {
        return match (true) {
            $text === strtoupper($text) && $text !== strtolower($text) => 'upper',
            $text === ucfirst(strtolower($text)) && $text !== strtolower($text) => 'title',
            default => 'lower',
        };
    }

    /** Java .properties escapes: \t \n \uXXXX, and \x for any other x. */
    private static function unescape(string $s): string
    {
        $s = (string) preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+/', function ($m) {
            $bin = (string) pack('n*', ...array_map('hexdec', str_split(str_replace('\\u', '', $m[0]), 4)));

            return (string) mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
        }, $s);

        return (string) preg_replace_callback('/\\\\(.)/', fn ($m) => match ($m[1]) {
            't' => "\t", 'n' => "\n", 'r' => "\r", 'f' => "\f", default => $m[1],
        }, $s);
    }

    /** A .properties line ending in an odd number of backslashes continues on the next. */
    private static function continues(string $text): bool
    {
        return preg_match('/(\\\\+)$/', $text, $m) === 1 && strlen($m[1]) % 2 === 1;
    }
}
