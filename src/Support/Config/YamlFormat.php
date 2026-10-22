<?php

namespace ServerSuite\Support\Config;

use Symfony\Component\Yaml\Yaml;

/**
 * Block-style YAML (Bukkit/Paper/Spigot plugin configs): nested maps of
 * scalars, plus lists of scalars in block (`- a`) or flow (`[a, b]`) form.
 * Every value the scanner reads is checked against symfony/yaml's reading of
 * the whole file, and a save must parse to exactly the expected data, so a
 * misread can't turn into a bad write.
 *
 * Left to the raw editor: anchors, aliases and merge keys (an edit would
 * change other settings too), several documents, tabs in indentation,
 * multi-line plain or quoted scalars. Left alone inside the form: block
 * scalars (| and >), tags, flow maps, lists of maps, nulls and dates.
 */
class YamlFormat extends Format
{
    private const KEY = '/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'|[^\s#\'"\-?:,\[\]{}&*!|>%@`][^#]*?|-[^\s#][^#]*?)\s*:(?=\s|$)/';

    public static function available(): bool
    {
        return class_exists(Yaml::class);
    }

    public static function scan(string $raw, string $variant): ?array
    {
        if (! self::available()) {
            return null;
        }
        try {
            $tree = Yaml::parse($raw);
        } catch (\Throwable) {
            return null;
        }
        // Anchors and aliases outside quotes and comments ("&a" colour codes in strings are fine).
        $bare = (string) preg_replace(['/"(?:[^"\\\n]|\\.)*"|\'(?:[^\'\n]|\'\')*\'/', '/(^|\s)#.*$/m'], ['""', '$1'], $raw);
        if (! is_array($tree)
            || preg_match('/(?:^|:\s+|-\s+|[\[{,]\s*)[&*][^\s,\[\]{}]+/m', $bare)
            || preg_match('/^\s*<<\s*:/m', $bare)) {
            return null;
        }

        $lines = self::lines($raw);
        $n = count($lines);
        $slots = [];
        $help = [];
        $stack = [];       // list<array{indent: int, path: list<string>}>
        $seenContent = false;

        for ($i = 0; $i < $n; $i++) {
            $text = $lines[$i]['text'];
            $t = ltrim($text, ' ');
            if (trim($t) === '') {
                $help = [];
                continue;
            }
            $indent = strlen($text) - strlen($t);
            if (str_starts_with($t, "\t")) {
                return null;
            }
            if ($t[0] === '#') {
                $help[] = self::commentText($t);
                continue;
            }
            if (rtrim($t) === '---' || rtrim($t) === '...') {
                if ($seenContent) {
                    return null;
                }
                continue;
            }
            $seenContent = true;

            while ($stack !== [] && end($stack)['indent'] >= $indent) {
                array_pop($stack);
            }
            if (rtrim($t) === '-' || str_starts_with($t, '- ')) {
                // A list item nobody collected (top-level list, list of maps
                // already skipped): not ours to edit.
                return null;
            }
            if (! preg_match(self::KEY, $t, $km)) {
                return null;   // something we don't understand (multi-line scalar, ...)
            }

            $key = self::key($km[1]);
            $path = array_merge($stack === [] ? [] : end($stack)['path'], [$key]);
            $afterColon = $lines[$i]['start'] + $indent + strlen($km[0]);
            $rest = substr($t, strlen($km[0]));
            $restTrim = ltrim($rest);
            $vStart = $afterColon + (strlen($rest) - strlen($restTrim));
            $note = ['help' => self::helpText($help)];
            $help = [];

            if ($restTrim === '' || $restTrim[0] === '#') {
                $next = self::nextContent($lines, $i + 1);
                $nIndent = $next === null ? -1 : strlen($lines[$next]['text']) - strlen(ltrim($lines[$next]['text'], ' '));
                $nText = $next === null ? '' : ltrim($lines[$next]['text'], ' ');

                if ($next !== null && $nIndent >= $indent && ($nText === '-' || str_starts_with($nText, '- '))) {
                    [$slot, $last] = self::blockList($lines, $next, $nIndent);
                    if ($slot !== null) {
                        $slot['editable'] = $slot['editable'] && $restTrim === '';
                        $slots[] = $note + ['path' => $path, 'start' => $afterColon] + $slot;
                    }
                    $i = $last;
                    continue;
                }
                if ($next !== null && $nIndent > $indent) {
                    $stack[] = ['indent' => $indent, 'path' => $path];
                }
                continue;   // a map, or null
            }

            $c = $restTrim[0];
            if ($c === '|' || $c === '>') {
                $i = self::skipDeeper($lines, $i + 1, $indent);
                continue;
            }
            if ($c === '!') {
                if (preg_match('/^!\S*\s*(#.*)?$/', $restTrim)) {
                    return null;   // a tagged container
                }
                continue;
            }
            if ($c === '[' || $c === '{') {
                $end = self::flowEnd($restTrim);
                if ($end === null || ! preg_match('/^\s*(#.*)?$/', substr($restTrim, $end))) {
                    return null;   // spans lines
                }
                if ($c === '[') {
                    $slot = self::flowList(substr($restTrim, 0, $end), $vStart);
                    if ($slot !== null) {
                        $slots[] = $note + ['path' => $path] + $slot;
                    }
                }
                continue;
            }

            if ($c === '"' || $c === "'") {
                $end = self::quoteEnd($restTrim, 0, $c === '"', $c === "'");
                if ($end === null) {
                    // A quoted string over several lines: leave it, skip to its closing quote.
                    $i = self::skipQuoted($lines, $i + 1, $c);
                    continue;
                }
                if (! preg_match('/^\s*(#.*)?$/', substr($restTrim, $end))) {
                    return null;
                }
                $token = substr($restTrim, 0, $end);
                $value = self::parseScalar($token);
                if (is_string($value)) {
                    $slots[] = $note + self::slot($path, 'string', $value, $vStart, $vStart + $end, ['style' => ['quote' => $c]]);
                }
                continue;
            }

            // Plain scalar, up to a " #" comment.
            $token = rtrim((string) preg_replace('/\s+#.*$/', '', $restTrim));
            $next = self::nextContent($lines, $i + 1);
            if ($next !== null) {
                $nText = ltrim($lines[$next]['text'], ' ');
                $nIndent = strlen($lines[$next]['text']) - strlen($nText);
                if ($nIndent > $indent && $nText[0] !== '#') {
                    // A plain scalar continued on the next lines: leave it alone.
                    $i = self::skipDeeper($lines, $i + 1, $indent);
                    continue;
                }
            }
            $slot = self::plainSlot($token, $vStart);
            if ($slot !== null) {
                $slots[] = $note + ['path' => $path] + $slot;
            }
        }

        $slots = self::markDuplicates($slots);

        // The scanner must agree with symfony/yaml on every value it offers.
        foreach ($slots as $s) {
            if ($s['editable'] && ! self::sameAt($tree, $s['path'], $s['value'])) {
                return null;
            }
        }

        return $slots;
    }

    public static function encode(array $slot, mixed $value): string
    {
        if ($slot['type'] === 'list') {
            $items = array_map(fn ($v) => self::scalarText($slot['item'], $v, 'plain'), $value);
            if (($slot['style']['list'] ?? '') === 'flow') {
                return '[' . implode(', ', $items) . ']';
            }
            if ($items === []) {
                return ' []';
            }
            $pad = str_repeat(' ', $slot['style']['indent']);
            $nl = $slot['style']['nl'];

            return $nl . implode($nl, array_map(fn ($s) => $pad . '- ' . $s, $items));
        }

        return self::scalarText($slot['type'], $value, $slot['style']['quote'] ?? 'plain');
    }

    public static function verify(string $old, string $new, array $slots, array $edits, string $variant): bool
    {
        try {
            $expected = Yaml::parse($old);
            foreach ($edits as $i => $value) {
                self::setAt($expected, $slots[$i]['path'], $value);
            }

            return Yaml::parse($new) === $expected;
        } catch (\Throwable) {
            return false;
        }
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
        if ($quote === '"') {
            return (string) json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $plainOk = $quote === 'plain' && $s !== '' && $s === trim($s)
            && ! preg_match('/:\s|\s#|:$|^[\-?:,\[\]{}#&*!|>\'"%@`]/', $s)
            && self::parseScalar($s) === $s;

        return $plainOk ? $s : "'" . str_replace("'", "''", $s) . "'";
    }

    private static function parseScalar(string $token): mixed
    {
        try {
            return Yaml::parse('k: ' . $token)['k'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private static function plainSlot(string $token, int $start): ?array
    {
        $value = self::parseScalar($token);
        $type = match (true) {
            is_bool($value) && preg_match('/^(true|false)$/i', $token) === 1 => 'bool',
            is_int($value) && preg_match('/^[-+]?\d+$/', $token) === 1 => 'int',
            is_float($value) && preg_match('/^[-+]?(\d+\.\d*|\.\d+)([eE][-+]?\d+)?$/', $token) === 1 => 'float',
            is_string($value) => 'string',
            default => null,   // null, dates, 0x1F, .inf ...: left alone
        };

        return $type === null ? null : self::slot([], $type, $value, $start, $start + strlen($token), ['style' => ['quote' => 'plain']]);
    }

    /** @return array<string, mixed>|null */
    private static function flowList(string $flow, int $start): ?array
    {
        try {
            $items = Yaml::parse('k: ' . $flow)['k'] ?? null;
        } catch (\Throwable) {
            return null;
        }
        $type = is_array($items) ? self::itemType($items) : null;

        return $type === null ? null : self::slot([], 'list', $items, $start, $start + strlen($flow), ['item' => $type, 'style' => ['list' => 'flow']]);
    }

    /**
     * A block list under a key. Returns the slot (start filled in by the
     * caller) and the last line index it covers.
     *
     * @param list<array{text: string, start: int, nl: string}> $lines
     *
     * @return array{0: array<string, mixed>|null, 1: int}
     */
    private static function blockList(array $lines, int $first, int $itemIndent): array
    {
        $items = [];
        $editable = true;
        $last = $first;
        $n = count($lines);
        for ($j = $first; $j < $n; $j++) {
            $text = $lines[$j]['text'];
            $t = ltrim($text, ' ');
            if (trim($t) === '') {
                continue;
            }
            $ind = strlen($text) - strlen($t);
            if ($ind < $itemIndent || ($ind === $itemIndent && $t !== '-' && ! str_starts_with($t, '- '))) {
                break;
            }
            $last = $j;
            if ($ind > $itemIndent || $t[0] === '#') {
                $editable = false;   // nested structure or a comment we'd lose
                continue;
            }
            $item = trim(substr($t, 1));
            $value = ($item === '' || preg_match('/^[\[{|>&*!-]|^[^\'"#]*?:(\s|$)/', $item)) ? null : self::parseScalar((string) preg_replace('/\s+#.*$/', '', $item));
            if ($value === null || is_array($value) || str_contains($item, ' #')) {
                $editable = false;
            }
            $items[] = $value;
        }

        $type = $editable ? self::itemType($items) : null;
        $end = $lines[$last]['start'] + strlen($lines[$last]['text']);
        $slot = self::slot([], 'list', $editable ? $items : null, 0, $end, [
            'item' => $type ?? 'string', 'editable' => $type !== null,
            'style' => ['list' => 'block', 'indent' => $itemIndent, 'nl' => $lines[$first]['nl'] !== '' ? $lines[$first]['nl'] : "\n"],
        ]);

        return [$slot, $last];
    }

    /** The one scalar type of a non-empty list, or null. @param array<mixed> $items */
    private static function itemType(array $items): ?string
    {
        if ($items === [] || ! array_is_list($items)) {
            return null;
        }
        $types = array_unique(array_map(fn ($v) => match (true) {
            is_bool($v) => 'bool', is_int($v) => 'int', is_float($v) => 'float', is_string($v) => 'string', default => 'x',
        }, $items));

        return count($types) === 1 && $types[0] !== 'x' ? $types[0] : null;
    }

    private static function key(string $token): string
    {
        if ($token[0] === '"' || $token[0] === "'") {
            $k = self::parseScalar($token);

            return is_string($k) ? $k : $token;
        }

        return rtrim($token);
    }

    /** @param list<array{text: string, start: int, nl: string}> $lines */
    private static function nextContent(array $lines, int $from): ?int
    {
        for ($j = $from, $n = count($lines); $j < $n; $j++) {
            $t = trim($lines[$j]['text']);
            if ($t !== '' && $t[0] !== '#') {
                return $j;
            }
        }

        return null;
    }

    /** @param list<array{text: string, start: int, nl: string}> $lines */
    private static function skipDeeper(array $lines, int $from, int $indent): int
    {
        $last = $from - 1;
        for ($j = $from, $n = count($lines); $j < $n; $j++) {
            $text = $lines[$j]['text'];
            if (trim($text) !== '' && strlen($text) - strlen(ltrim($text, ' ')) <= $indent) {
                break;
            }
            $last = $j;
        }

        return $last;
    }

    /**
     * The line where a quoted scalar that started on an earlier line closes.
     *
     * @param list<array{text: string, start: int, nl: string}> $lines
     */
    private static function skipQuoted(array $lines, int $from, string $q): int
    {
        for ($j = $from, $n = count($lines); $j < $n; $j++) {
            $t = $q === "'" ? str_replace("''", '', $lines[$j]['text']) : (string) preg_replace('/\\\\./', '', $lines[$j]['text']);
            if (str_contains($t, $q)) {
                return $j;
            }
        }

        return $n - 1;
    }

    /** Where a one-line flow collection closes, or null. */
    private static function flowEnd(string $s): ?int
    {
        $depth = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $c = $s[$i];
            if ($c === '"' || $c === "'") {
                $e = self::quoteEnd($s, $i, $c === '"', $c === "'");
                if ($e === null) {
                    return null;
                }
                $i = $e - 1;
                continue;
            }
            if ($c === '[' || $c === '{') {
                $depth++;
            } elseif ($c === ']' || $c === '}') {
                if (--$depth === 0) {
                    return $i + 1;
                }
            }
        }

        return null;
    }

    /** @param list<string> $path */
    private static function sameAt(mixed $tree, array $path, mixed $value): bool
    {
        foreach ($path as $k) {
            if (! is_array($tree) || ! array_key_exists($k, $tree)) {
                return false;
            }
            $tree = $tree[$k];
        }

        return $tree === $value;
    }

    /** @param list<string> $path */
    private static function setAt(mixed &$tree, array $path, mixed $value): void
    {
        $ref = &$tree;
        foreach ($path as $k) {
            $ref = &$ref[$k];
        }
        $ref = $value;
    }
}
