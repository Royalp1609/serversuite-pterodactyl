<?php

namespace ServerSuite\Support;

/**
 * Reads a Minecraft crash report or log and pulls out which mods it blames
 * (#18). Pure: text in, suspects out. Each suspect names a mod id, a mod
 * name or a jar file, with the evidence and how strong it is:
 *
 *   strong - the loader named it: Fabric "Incompatible mods found" details,
 *            "Error analyzing [mods/x.jar]", Forge/NeoForge "Mod File:",
 *            Paper "Could not load 'plugins/x.jar'" / "Error occurred while
 *            enabling X", the crash report's "Suspected Mod(s)" section
 *   weak   - a mod's package appears in the stack trace
 */
final class CrashParser
{
    /**
     * @param string[] $knownPackages  mod slug => normalised slug, for the weak package match
     * @return array{failed: bool, summary: ?string, suspects: array<int, array{id: ?string, name: ?string, file: ?string, evidence: string, strength: string}>}
     */
    public static function parse(string $text, array $knownPackages = []): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $suspects = [];
        $add = function (?string $id, ?string $name, ?string $file, string $evidence, string $strength = 'strong') use (&$suspects) {
            $key = strtolower($file ?? $id ?? $name ?? '');
            if ($key === '' || isset($suspects[$key])) {
                return;
            }
            $suspects[$key] = ['id' => $id, 'name' => $name, 'file' => $file, 'evidence' => mb_substr(trim($evidence), 0, 300), 'strength' => $strength];
        };

        // Fabric: "Mod 'Sodium Extra' (sodium-extra) 0.5.1 requires ..." /
        // "Remove mod 'X' (x)" lines under "Incompatible mods found!".
        if (preg_match('/Incompatible mods? found!?(.*?)(?:\n\S|\z)/s', $text, $block)) {
            preg_match_all("/(?:^|\\s)-\\s+(?:Remove |Replace )?[Mm]od '([^']+)' \\(([a-z0-9_.-]+)\\)[^\n]*/m", $text, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $add($hit[2], $hit[1], null, $hit[0]);
            }
        }
        // Fabric: a jar it couldn't read at all.
        preg_match_all('/Error analyzing \[[^\]]*?\/((?:[^\/\]]+)\.jar)\]:?([^\n]*)/', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $add(null, null, $hit[1], 'Error analyzing ' . $hit[1] . ':' . $hit[2]);
        }

        // Crash report "Suspected Mod(s):" section (Fabric and Forge/NeoForge).
        if (preg_match('/Suspected Mods?:[ \t]*(.*?)(?:\n[ \t]*\n|\nStacktrace:|\z)/s', $text, $sec)) {
            preg_match_all('/([^\n\t:]+?) \(([a-z0-9_.-]+)\), Version: [^\n]+/', $sec[1], $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $add($hit[2], trim($hit[1]), null, 'Suspected Mod: ' . trim($hit[0]));
            }
        }

        // Forge / NeoForge mod loading errors: "Mod File: /home/container/mods/x.jar".
        preg_match_all('/Mod File: [^\n]*?\/([^\/\n]+\.jar)/', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $failure = preg_match('/Mod File: [^\n]*' . preg_quote($hit[1], '/') . '\s*\n(?:[^\n]*\n){0,3}?\s*Failure message: ([^\n]+)/', $text, $f) ? $f[1] : 'mod loading failed';
            $add(null, null, $hit[1], $hit[1] . ': ' . $failure);
        }

        // Paper / Spigot plugins.
        preg_match_all("/Could not load '(?:[^']*\\/)?([^'\\/]+\\.jar)' in folder '[^']*'[^\n]*/", $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $add(null, null, $hit[1], $hit[0]);
        }
        preg_match_all('/Error occurred while enabling ([^\s(]+)(?: v?\S+)?[^\n]*/', $text, $m, PREG_SET_ORDER);
        foreach ($m as $hit) {
            $add(null, $hit[1], null, $hit[0]);
        }

        // Weak: a known mod's package in the stack trace.
        if ($suspects === [] && $knownPackages !== []) {
            preg_match_all('/^\s*at ([a-z0-9_.$]+)\./mi', $text, $frames);
            foreach (array_unique($frames[1]) as $frame) {
                $segments = array_map(fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower($s)), explode('.', $frame));
                foreach ($knownPackages as $slug => $norm) {
                    if ($norm !== '' && strlen($norm) >= 4 && in_array($norm, array_slice($segments, 0, 4), true)
                        && ! in_array($norm, ['minecraft', 'mojang', 'fabricmc', 'minecraftforge', 'neoforged', 'bukkit', 'papermc'], true)) {
                        $add((string) $slug, null, null, 'in the stack trace: at ' . $frame, 'weak');
                    }
                }
            }
        }

        $failed = $suspects !== [] || (bool) preg_match('/(---- Minecraft Crash Report ----|Incompatible mods found|Failed to start the minecraft server|Encountered an unexpected exception|Exception in server tick loop|Mod resolution failed|ModLoadingException|Missing or unsupported mandatory dependencies)/', $text);

        return [
            'failed'   => $failed,
            'summary'  => self::summary($text),
            'suspects' => array_values($suspects),
        ];
    }

    /** The most telling single line: the crash description, else the first cause. */
    private static function summary(string $text): ?string
    {
        foreach (['/^Description: ([^\n]+)/m', '/Incompatible mods found!?/', '/^Caused by: ([^\n]+)/m', '/\[(?:main|Server thread)\/ERROR\][^:]*: ([^\n]+)/'] as $re) {
            if (preg_match($re, $text, $m)) {
                return mb_substr(trim($m[1] ?? $m[0]), 0, 240);
            }
        }

        return null;
    }
}
