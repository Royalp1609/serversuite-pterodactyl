<?php

namespace ServerSuite\Support;

/**
 * Which Minecraft versions an automatic upgrade may move to (#111). Pure, so
 * the rules can be tested without the panel.
 *
 * A "line" is the first two numbers of a version: 1.21 for 1.21.4, and 26.1
 * for 26.1.2, so the old and the year-based numbering work the same way.
 */
class AutoUpgradePolicy
{
    public static function line(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }

    /**
     * Releases newer than $current that $range allows, newest first.
     *
     * @param  string[] $versions
     * @param  string   $range line | latest
     * @return string[]
     */
    public static function candidates(string $current, array $versions, string $range): array
    {
        $out = array_filter($versions, fn (string $v) => self::isRelease($v)
            && version_compare($v, $current, '>')
            && ($range === 'latest' || self::line($v) === self::line($current)));
        usort($out, fn (string $a, string $b) => version_compare($b, $a));

        return array_values($out);
    }

    /**
     * The newest release in a later line than $current, or null.
     *
     * @param string[] $versions
     */
    public static function newerLine(string $current, array $versions): ?string
    {
        $newer = array_filter(self::candidates($current, $versions, 'latest'), fn (string $v) => self::line($v) !== self::line($current));

        return array_values($newer)[0] ?? null;
    }

    private static function isRelease(string $version): bool
    {
        return preg_match('/^\d+(?:\.\d+)+$/', $version) === 1;
    }
}
