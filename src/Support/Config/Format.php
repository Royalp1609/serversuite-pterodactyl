<?php

namespace ServerSuite\Support\Config;

/**
 * One config file format for the typed editor (#8).
 *
 * scan() finds every setting it can read and records where its value sits
 * in the text, so a save replaces those characters and nothing else:
 * comments, ordering and formatting stay as they are. A setting the
 * scanner can't edit safely is still listed (editable false) so ids stay
 * stable; it is never written. scan() returns null when it can't read the
 * file safely at all: the editor then offers raw text only.
 *
 * A slot is:
 *   path      list<string>  keys from the root
 *   type      bool | int | float | string | list
 *   item      for lists: the type of every item (bool | int | float | string)
 *   value     the current value (a list for lists)
 *   start/end byte offsets of the value text in the file
 *   style     format-specific hints for encode() (quote style, list layout)
 *   help      the comment lines just above the setting
 *   editable  false when the form must leave it alone
 *
 * @phpstan-type Slot array{path: list<string>, type: string, item?: string, value: mixed, start: int, end: int, style: array<string, mixed>, help: string, editable: bool}
 */
abstract class Format
{
    /** @return list<Slot>|null */
    abstract public static function scan(string $raw, string $variant): ?array;

    /** The text that replaces a slot's value span. */
    abstract public static function encode(array $slot, mixed $value): string;

    /**
     * A check against a full parser of the format, on top of the editor's
     * own re-scan. $edits maps slot index => new value.
     *
     * @param list<Slot> $slots
     * @param array<int, mixed> $edits
     */
    public static function verify(string $old, string $new, array $slots, array $edits, string $variant): bool
    {
        return true;
    }

    /** What a re-read must agree on for a slot (its type and value by default). */
    public static function normalize(array $slot): mixed
    {
        return [$slot['type'], $slot['value']];
    }

    /**
     * Lines with their offsets and line endings (kept as they are).
     *
     * @return list<array{text: string, start: int, nl: string}>
     */
    protected static function lines(string $raw): array
    {
        preg_match_all('/([^\r\n]*)(\r\n|\r|\n|$)/', $raw, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m as $x) {
            if ($x[0][0] === '' && $x[0][1] === strlen($raw) && $out !== []) {
                break;
            }
            $out[] = ['text' => $x[1][0], 'start' => $x[1][1], 'nl' => $x[2][0]];
        }

        return $out;
    }

    /** Comment text without its marker, for the field's help line. */
    protected static function commentText(string $line): string
    {
        return trim((string) preg_replace('/^\s*(#+|;+|\/\/+|!)\s?/', '', $line));
    }

    /** @param list<string> $help */
    protected static function helpText(array $help): string
    {
        $text = trim(implode("\n", $help));

        return mb_strlen($text) > 600 ? mb_substr($text, 0, 600) . '…' : $text;
    }

    /**
     * @param list<string> $path
     * @param array<string, mixed> $extra
     *
     * @return Slot
     */
    protected static function slot(array $path, string $type, mixed $value, int $start, int $end, array $extra = []): array
    {
        return $extra + [
            'path' => $path, 'type' => $type, 'value' => $value, 'start' => $start, 'end' => $end,
            'style' => [], 'help' => '', 'editable' => true,
        ];
    }

    /**
     * Mark every slot whose path appears more than once: which copy wins
     * differs between parsers, so neither is edited.
     *
     * @param list<Slot> $slots
     *
     * @return list<Slot>
     */
    protected static function markDuplicates(array $slots): array
    {
        $count = [];
        foreach ($slots as $s) {
            $k = implode("\0", $s['path']);
            $count[$k] = ($count[$k] ?? 0) + 1;
        }
        foreach ($slots as $i => $s) {
            if ($count[implode("\0", $s['path'])] > 1) {
                $slots[$i]['editable'] = false;
            }
        }

        return $slots;
    }

    /** A float as text that every parser reads back as a float. */
    protected static function floatText(float $f): string
    {
        $s = json_encode($f);
        if (! is_string($s) || ! is_finite($f)) {
            throw new ConfigEditException('That number is out of range.');
        }

        return preg_match('/[.eE]/', $s) ? $s : $s . '.0';
    }

    /**
     * Find the end of a quoted token starting at $pos ($s[$pos] is the quote).
     * Backslash escapes count when $escapes; a doubled quote counts when
     * $doubled (YAML single quotes). Returns the offset after the closing
     * quote, or null when it doesn't close on this text.
     */
    protected static function quoteEnd(string $s, int $pos, bool $escapes, bool $doubled = false): ?int
    {
        $q = $s[$pos];
        $n = strlen($s);
        for ($i = $pos + 1; $i < $n; $i++) {
            if ($escapes && $s[$i] === '\\') {
                $i++;
                continue;
            }
            if ($s[$i] === $q) {
                if ($doubled && $i + 1 < $n && $s[$i + 1] === $q) {
                    $i++;
                    continue;
                }

                return $i + 1;
            }
        }

        return null;
    }
}
