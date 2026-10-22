<?php

namespace ServerSuite\Support\Config;

/**
 * The typed config editor (#8): turns a config file into form fields and
 * writes edited values back in place.
 *
 * open() lists the settings: toggles for booleans, number inputs (with the
 * range when the file states one), dropdowns for known choices, text inputs,
 * and one-item-per-line boxes for lists. save() replaces only the edited
 * values' characters, then re-reads the result two ways (the scanner again,
 * plus a full parser where the format has one) and refuses unless exactly
 * the edited settings changed. Nothing is written when it refuses.
 *
 * Field ids are slot indexes, so save() must get the same text open() saw.
 */
class ConfigEditor
{
    /** extension => [format class, variant] */
    private const FORMATS = [
        'yml'        => [YamlFormat::class, 'yaml'],
        'yaml'       => [YamlFormat::class, 'yaml'],
        'toml'       => [TomlFormat::class, 'toml'],
        'json'       => [JsonFormat::class, 'json'],
        'json5'      => [JsonFormat::class, 'json5'],
        'properties' => [KeyValueFormat::class, 'properties'],
        'ini'        => [KeyValueFormat::class, 'ini'],
        'cfg'        => [KeyValueFormat::class, 'cfg'],
        'conf'       => [KeyValueFormat::class, 'conf'],
    ];

    /** Choices for common Minecraft settings, used when the current value is one of them. */
    private const CHOICES = [
        'difficulty' => ['peaceful', 'easy', 'normal', 'hard'],
        'gamemode'   => ['survival', 'creative', 'adventure', 'spectator'],
    ];

    private const LONG_TEXT = 100;

    /** @return array{0: class-string<Format>, 1: string}|null */
    public static function formatFor(string $filename): ?array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $f = self::FORMATS[$ext] ?? null;
        if ($f !== null && $f[0] === YamlFormat::class && ! YamlFormat::available()) {
            return null;
        }

        return $f;
    }

    /**
     * The form for a file, or a reason it has none.
     *
     * @return array{fields: list<array<string, mixed>>, reason: ?string}
     */
    public static function open(string $raw, string $filename): array
    {
        $fmt = self::formatFor($filename);
        if ($fmt === null) {
            return ['fields' => [], 'reason' => 'There is no form view for this file type.'];
        }
        $slots = self::slots($raw, $fmt);
        if ($slots === null) {
            return ['fields' => [], 'reason' => "The form can't read this file safely (it uses features the form doesn't edit), so it opens as text."];
        }

        $fields = [];
        foreach ($slots as $id => $s) {
            if ($s['editable']) {
                $fields[] = self::field($id, $s);
            }
        }

        return $fields === []
            ? ['fields' => [], 'reason' => 'This file has no settings the form can edit.']
            : ['fields' => $fields, 'reason' => null];
    }

    /**
     * Apply form values (field id => input) to the file text.
     *
     * @param array<int|string, mixed> $values
     *
     * @return array{content: string, changed: list<string>}
     *
     * @throws ConfigEditException
     */
    public static function save(string $raw, string $filename, array $values): array
    {
        $fmt = self::formatFor($filename) ?? throw new ConfigEditException('There is no form view for this file type.');
        [$class, $variant] = $fmt;
        $slots = self::slots($raw, $fmt) ?? throw new ConfigEditException("The form can't read this file safely any more. Use the text editor.");

        $edits = [];
        foreach ($values as $id => $input) {
            $slot = $slots[(int) $id] ?? null;
            if ($slot === null || ! $slot['editable']) {
                throw new ConfigEditException('The file changed since the form was opened. Reopen it.');
            }
            $field = self::field((int) $id, $slot);
            if (self::unchanged($field, $input)) {
                continue;   // untouched fields aren't re-validated (a port of 0 may mean "off")
            }
            $value = self::coerce($field, $input);
            if ($value !== $slot['value']) {
                $edits[(int) $id] = $value;
            }
        }
        if ($edits === []) {
            return ['content' => $raw, 'changed' => []];
        }

        $new = $raw;
        $order = array_keys($edits);
        usort($order, fn ($a, $b) => $slots[$b]['start'] <=> $slots[$a]['start']);
        foreach ($order as $id) {
            $s = $slots[$id];
            $new = substr_replace($new, $class::encode($s, $edits[$id]), $s['start'], $s['end'] - $s['start']);
        }

        if (! self::verify($raw, $new, $slots, $edits, $fmt)) {
            throw new ConfigEditException("Save blocked: the edited file didn't read back as expected, so nothing was written. Use the text editor for this change.");
        }

        return ['content' => $new, 'changed' => array_map(fn ($id) => self::label($slots[$id]['path']), array_keys($edits))];
    }

    // -------------------------------------------------------------------------

    /**
     * @param array{0: class-string<Format>, 1: string} $fmt
     *
     * @return list<array<string, mixed>>|null
     */
    private static function slots(string $raw, array $fmt): ?array
    {
        try {
            return $fmt[0]::scan($raw, $fmt[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Re-read the result: the same settings in the same order, the edited
     * ones holding their new values and every other one unchanged; then the
     * format's own full-parser check.
     *
     * @param list<array<string, mixed>> $slots
     * @param array<int, mixed> $edits
     * @param array{0: class-string<Format>, 1: string} $fmt
     */
    private static function verify(string $raw, string $new, array $slots, array $edits, array $fmt): bool
    {
        [$class, $variant] = $fmt;
        $again = self::slots($new, $fmt);
        if ($again === null || count($again) !== count($slots)) {
            return false;
        }
        foreach ($slots as $i => $s) {
            $expect = array_key_exists($i, $edits) ? ['value' => $edits[$i]] + $s : $s;
            $got = $again[$i];
            if ($got['path'] !== $s['path'] || $got['editable'] !== $s['editable']) {
                return false;
            }
            if ($s['editable'] && $class::normalize($got) !== $class::normalize($expect)) {
                return false;
            }
        }

        return $class::verify($raw, $new, $slots, $edits, $variant);
    }

    /**
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    private static function field(int $id, array $s): array
    {
        $type = $s['type'];
        $f = [
            'id' => $id, 'label' => self::label($s['path']), 'help' => $s['help'], 'type' => $type,
            'item' => $s['item'] ?? null, 'choices' => null, 'min' => null, 'max' => null,
        ];

        if ($type === 'list') {
            $f['control'] = 'list';
            $f['value'] = implode("\n", array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $s['value']));

            return $f;
        }

        [$min, $max, $choices] = self::hints($s['help']);
        $leaf = strtolower((string) end($s['path']));
        if ($choices === null && $type === 'string') {
            foreach (self::CHOICES as $k => $opts) {
                if (($leaf === $k || str_ends_with($leaf, '-' . $k) || str_ends_with($leaf, '_' . $k)) && in_array(strtolower($s['value']), $opts, true)) {
                    $choices = $s['value'] === strtolower($s['value']) ? $opts : array_map('strtoupper', $opts);
                }
            }
        }
        if (in_array($type, ['int', 'float'], true) && $min === null && preg_match('/(^|[-_.])port$/', $leaf) && $type === 'int') {
            [$min, $max] = [0, 65535];
        }

        $f['min'] = in_array($type, ['int', 'float'], true) ? $min : null;
        $f['max'] = in_array($type, ['int', 'float'], true) ? $max : null;
        if ($choices !== null && $type === 'string' && in_array($s['value'], $choices, true)) {
            $f['choices'] = $choices;
        }
        $f['control'] = match (true) {
            $type === 'bool' => 'toggle',
            $f['choices'] !== null => 'select',
            $type === 'int' || $type === 'float' => 'number',
            mb_strlen((string) $s['value']) > self::LONG_TEXT => 'textarea',
            default => 'text',
        };
        $f['value'] = match ($type) {
            'bool'  => (bool) $s['value'],
            'float' => self::floatInput((float) $s['value']),
            default => (string) $s['value'],
        };

        return $f;
    }

    /**
     * Ranges and choices that Forge/NeoForge configs state in the comment
     * above a setting ("Range: 1 ~ 64", "Range: > 0", "Allowed Values: A, B").
     *
     * @return array{0: int|float|null, 1: int|float|null, 2: list<string>|null}
     */
    private static function hints(string $help): array
    {
        $num = '-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?';
        $min = $max = $choices = null;
        if (preg_match("/Range:\s*($num)\s*~\s*($num)/i", $help, $m)) {
            [$min, $max] = [$m[1] + 0, $m[2] + 0];
        } elseif (preg_match("/Range:\s*>\s*=?\s*($num)/i", $help, $m)) {
            $min = $m[1] + 0;
        } elseif (preg_match("/Range:\s*<\s*=?\s*($num)/i", $help, $m)) {
            $max = $m[1] + 0;
        }
        if (abs((float) $min) >= 1e15) {
            $min = null;
        }
        if (abs((float) $max) >= 1e15) {
            $max = null;
        }
        if (preg_match('/Allowed Values:\s*(.+)$/im', $help, $m)) {
            $choices = array_values(array_filter(array_map('trim', explode(',', $m[1])), fn ($c) => $c !== ''));
        }

        return [$min, $max, $choices ?: null];
    }

    /**
     * A form input as the slot's type, or a message saying why not.
     *
     * @param array<string, mixed> $f
     *
     * @throws ConfigEditException
     */
    private static function coerce(array $f, mixed $input): mixed
    {
        $label = $f['label'];
        if ($f['type'] === 'list') {
            $items = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $input) ?: []), fn ($l) => $l !== ''));
            if ($items === []) {
                // An empty list has no item type left to check the save against.
                throw new ConfigEditException("{$label}: a list can't be emptied from the form. Use the text editor.");
            }

            return array_map(fn ($v) => self::scalar($f['item'], $v, $label, null, null), $items);
        }
        if ($f['type'] === 'bool') {
            return is_bool($input) ? $input : filter_var($input, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? throw new ConfigEditException("{$label}: must be on or off.");
        }
        $value = self::scalar($f['type'], (string) $input, $label, $f['min'], $f['max']);
        if ($f['choices'] !== null && ! in_array($value, $f['choices'], true)) {
            throw new ConfigEditException("{$label}: pick one of " . implode(', ', $f['choices']) . '.');
        }

        return $value;
    }

    /** @throws ConfigEditException */
    private static function scalar(string $type, string $v, string $label, int|float|null $min, int|float|null $max): mixed
    {
        $t = trim($v);
        $value = match ($type) {
            'bool'  => filter_var($t, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
                ?? throw new ConfigEditException("{$label}: \"{$t}\" isn't true or false."),
            'int'   => preg_match('/^[+-]?\d{1,18}$/', $t) ? (int) $t
                : throw new ConfigEditException("{$label}: \"{$t}\" isn't a whole number."),
            'float' => preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/', $t) && is_finite((float) $t) ? (float) $t
                : throw new ConfigEditException("{$label}: \"{$t}\" isn't a number."),
            default => preg_match('/[\r\n]/', $v) ? throw new ConfigEditException("{$label}: can't contain line breaks.") : $v,
        };
        if ($min !== null && $value < $min) {
            throw new ConfigEditException("{$label}: the lowest allowed value is {$min}.");
        }
        if ($max !== null && $value > $max) {
            throw new ConfigEditException("{$label}: the highest allowed value is {$max}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $f */
    private static function unchanged(array $f, mixed $input): bool
    {
        if ($f['control'] === 'toggle') {
            return filter_var($input, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === $f['value'];
        }
        if ($f['control'] === 'list') {
            $lines = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $s) ?: []), fn ($l) => $l !== ''));

            return $lines($input) === $lines($f['value']);
        }

        return is_scalar($input) && (string) $input === (string) $f['value'];
    }

    private static function floatInput(float $f): string
    {
        $s = json_encode($f);

        return is_string($s) ? $s : (string) $f;
    }

    /** @param list<string> $path */
    private static function label(array $path): string
    {
        return implode('.', $path);
    }
}
