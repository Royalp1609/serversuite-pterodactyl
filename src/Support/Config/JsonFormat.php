<?php

namespace ServerSuite\Support\Config;

/**
 * JSON and JSON5 (Fabric/Quilt mod configs). The parser keeps every
 * value's position, so an edit replaces just that value. Comments and
 * trailing commas are accepted in both (many .json configs have them);
 * JSON5 also allows single quotes and unquoted keys. Arrays of one scalar
 * type become list fields; a value inside an array of objects is a setting
 * of its own (`servers.0.port`). Left alone: null, empty arrays, arrays with
 * comments inside, and JSON5-only numbers (hex, Infinity, NaN, +1, .5).
 * A save must parse back to exactly the expected data; a plain .json without
 * comments must also pass PHP's json_decode.
 */
class JsonFormat extends Format
{
    private string $s;
    private int $p = 0;
    private bool $json5;
    /** @var list<array<string, mixed>> */
    private array $slots = [];
    private bool $sawComment = false;

    private function __construct(string $s, bool $json5)
    {
        $this->s = $s;
        $this->json5 = $json5;
    }

    public static function scan(string $raw, string $variant): ?array
    {
        $parser = new self($raw, $variant === 'json5');
        try {
            $parser->tree();
        } catch (\RuntimeException) {
            return null;
        }

        return self::markDuplicates($parser->slots);
    }

    public static function encode(array $slot, mixed $value): string
    {
        if ($slot['type'] === 'list') {
            $items = array_map(fn ($v) => self::scalarText($slot['item'], $v, '"'), $value);
            $st = $slot['style'];
            if (! ($st['multiline'] ?? false) || $items === []) {
                return '[' . implode($st['sep'] ?? ', ', $items) . ']';
            }

            return '[' . $st['nl'] . implode(',' . $st['nl'], array_map(fn ($i) => $st['indent'] . $i, $items)) . $st['nl'] . $st['close'] . ']';
        }

        return self::scalarText($slot['type'], $value, $slot['style']['quote'] ?? '"');
    }

    public static function verify(string $old, string $new, array $slots, array $edits, string $variant): bool
    {
        try {
            $expected = (new self($old, $variant === 'json5'))->tree();
            foreach ($edits as $i => $value) {
                $ref = &$expected;
                foreach ($slots[$i]['path'] as $k) {
                    $ref = &$ref[$k];
                }
                $ref = $value;
                unset($ref);
            }
            $reader = new self($new, $variant === 'json5');
            if ($reader->tree() !== $expected) {
                return false;
            }
            if ($variant === 'json' && ! $reader->sawComment) {
                return json_decode($new, true, 512, JSON_THROW_ON_ERROR) === $expected;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------

    private static function scalarText(string $type, mixed $value, string $quote): string
    {
        $json = match ($type) {
            'bool'  => $value ? 'true' : 'false',
            'int'   => (string) $value,
            'float' => self::floatText((float) $value),
            default => (string) json_encode((string) $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
        if ($type === 'string' && $quote === "'") {
            return "'" . str_replace(["\\\"", "'"], ['"', "\\'"], substr($json, 1, -1)) . "'";
        }

        return $json;
    }

    /** The whole document as PHP data (objects as arrays), recording slots. */
    private function tree(): mixed
    {
        $this->slots = [];
        $this->p = 0;
        $this->ws();
        $v = $this->value([]);
        $this->ws();
        if ($this->p !== strlen($this->s)) {
            throw new \RuntimeException('trailing data');
        }

        return $v;
    }

    /** @param list<string> $path */
    private function value(array $path): mixed
    {
        $c = $this->s[$this->p] ?? '';
        $start = $this->p;

        if ($c === '{') {
            return $this->object($path);
        }
        if ($c === '[') {
            return $this->array($path);
        }
        if ($c === '"' || ($c === "'" && $this->json5)) {
            $v = $this->string();
            $this->slots[] = self::slot($path, 'string', $v, $start, $this->p, ['style' => ['quote' => $c]]);

            return $v;
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $word => $v) {
            if (substr_compare($this->s, $word, $this->p, strlen($word)) === 0) {
                $this->p += strlen($word);
                if ($v !== null) {
                    $this->slots[] = self::slot($path, 'bool', $v, $start, $this->p);
                }

                return $v;
            }
        }
        if (preg_match('/\G-?(0|[1-9]\d*)(\.\d+)?([eE][+-]?\d+)?(?![.xX\d])/', $this->s, $m, 0, $this->p) && $m[0] !== '' && $m[0] !== '-') {
            $this->p += strlen($m[0]);
            $isFloat = ($m[2] ?? '') !== '' || ($m[3] ?? '') !== '';
            $v = $isFloat ? (float) $m[0] : (int) $m[0];
            if (! $isFloat && (string) $v !== ltrim($m[0], '+')) {
                $v = (float) $m[0];   // beyond PHP_INT_MAX: json_decode reads a float too
                $isFloat = true;
            }
            $this->slots[] = self::slot($path, $isFloat ? 'float' : 'int', $v, $start, $this->p);

            return $v;
        }
        if ($this->json5 && preg_match('/\G[+-]?(0[xX][0-9a-fA-F]+|Infinity|NaN|\d*\.\d*([eE][+-]?\d+)?|\d+)/', $this->s, $m, 0, $this->p) && $m[0] !== '') {
            $this->p += strlen($m[0]);
            $t = ltrim($m[0], '+');
            $v = match (true) {
                stripos($t, '0x') !== false => (int) hexdec(ltrim($t, '-0xX')) * ($t[0] === '-' ? -1 : 1),
                str_contains($t, 'Infinity') => $t[0] === '-' ? -INF : INF,
                $t === 'NaN' || $t === '-NaN' => NAN,
                default => (float) $t,
            };
            $this->slots[] = self::slot($path, 'float', $v, $start, $this->p, ['editable' => false]);

            return $v;
        }

        throw new \RuntimeException('unexpected character');
    }

    /** @param list<string> $path */
    private function object(array $path): array
    {
        $this->p++;
        $out = [];
        while (true) {
            $this->ws();
            if (($this->s[$this->p] ?? '') === '}') {
                $this->p++;

                return $out;
            }
            $key = $this->key();
            $this->ws();
            if (($this->s[$this->p] ?? '') !== ':') {
                throw new \RuntimeException('expected :');
            }
            $this->p++;
            $this->ws();
            $out[$key] = $this->value(array_merge($path, [$key]));
            $this->ws();
            $c = $this->s[$this->p] ?? '';
            if ($c === ',') {
                $this->p++;
            } elseif ($c !== '}') {
                throw new \RuntimeException('expected , or }');
            }
        }
    }

    /** @param list<string> $path */
    private function array(array $path): array
    {
        $start = $this->p;
        $first = count($this->slots);
        $comments = $this->sawComment;
        $this->sawComment = false;
        $firstItemAt = null;
        $this->p++;
        $out = [];
        while (true) {
            $this->ws();
            if (($this->s[$this->p] ?? '') === ']') {
                $this->p++;
                break;
            }
            $firstItemAt ??= $this->p;
            $out[] = $this->value(array_merge($path, [(string) count($out)]));
            $this->ws();
            $c = $this->s[$this->p] ?? '';
            if ($c === ',') {
                $this->p++;
            } elseif ($c !== ']') {
                throw new \RuntimeException('expected , or ]');
            }
        }
        $innerComments = $this->sawComment;
        $this->sawComment = $comments || $innerComments;

        // An array of one scalar type is one list field, not a field per item.
        $children = array_slice($this->slots, $first);
        $type = self::itemType($out);
        if ($type !== null && count($children) === count($out)) {
            $this->slots = array_slice($this->slots, 0, $first);
            $text = substr($this->s, $start, $this->p - $start);
            $multiline = str_contains($text, "\n");
            $this->slots[] = self::slot($path, 'list', $out, $start, $this->p, [
                'item' => $type,
                'editable' => ! $innerComments && array_reduce($children, fn ($ok, $c) => $ok && $c['editable'], true),
                'style' => [
                    'multiline' => $multiline,
                    'sep' => preg_match('/,\s/', $text) ? ', ' : ',',
                    'nl' => str_contains($text, "\r\n") ? "\r\n" : "\n",
                    'indent' => $multiline && $firstItemAt !== null ? $this->indentAt($firstItemAt) : '',
                    'close' => $multiline ? $this->indentAt($this->p - 1) : '',
                ],
            ]);
        }

        return $out;
    }

    /** @param array<mixed> $items */
    private static function itemType(array $items): ?string
    {
        if ($items === []) {
            return null;
        }
        $types = array_unique(array_map(fn ($v) => match (true) {
            is_bool($v) => 'bool', is_int($v) => 'int', is_float($v) => 'float', is_string($v) => 'string', default => 'x',
        }, $items));

        return count($types) === 1 && $types[0] !== 'x' ? $types[0] : null;
    }

    /** The whitespace before $pos on its line. */
    private function indentAt(int $pos): string
    {
        $lineStart = strrpos(substr($this->s, 0, $pos), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        preg_match('/^[ \t]*/', substr($this->s, $lineStart, $pos - $lineStart), $m);

        return $m[0];
    }

    private function key(): string
    {
        $c = $this->s[$this->p] ?? '';
        if ($c === '"' || ($c === "'" && $this->json5)) {
            return $this->string();
        }
        if ($this->json5 && preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $this->s, $m, 0, $this->p)) {
            $this->p += strlen($m[0]);

            return $m[0];
        }
        throw new \RuntimeException('expected a key');
    }

    private function string(): string
    {
        $q = $this->s[$this->p];
        $end = self::quoteEnd($this->s, $this->p, true);
        if ($end === null) {
            throw new \RuntimeException('unterminated string');
        }
        $body = substr($this->s, $this->p + 1, $end - $this->p - 2);
        $this->p = $end;
        if (str_contains($body, "\n")) {
            throw new \RuntimeException('line break in string');
        }
        if ($q === "'") {
            $body = str_replace(["\\'", '"'], ["'", '\\"'], $body);
        }
        $v = json_decode('"' . $body . '"');
        if (! is_string($v)) {
            throw new \RuntimeException('bad string');
        }

        return $v;
    }

    /** Whitespace and comments. */
    private function ws(): void
    {
        $n = strlen($this->s);
        while ($this->p < $n) {
            $c = $this->s[$this->p];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->p++;
            } elseif ($c === '/' && ($this->s[$this->p + 1] ?? '') === '/') {
                $e = strpos($this->s, "\n", $this->p);
                $this->p = $e === false ? $n : $e;
                $this->sawComment = true;
            } elseif ($c === '/' && ($this->s[$this->p + 1] ?? '') === '*') {
                $e = strpos($this->s, '*/', $this->p + 2);
                if ($e === false) {
                    throw new \RuntimeException('unterminated comment');
                }
                $this->p = $e + 2;
                $this->sawComment = true;
            } else {
                return;
            }
        }
    }
}
