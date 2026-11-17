<?php

namespace ServerSuite\Support;

/**
 * Groups the warnings and errors in a Minecraft server log (#41). Pure:
 * text in, groups out. A log entry is a timestamped line plus the lines
 * after it that have no timestamp (stack traces). Entries with the same
 * level, message shape (numbers, UUIDs and hex left out) and exception are
 * one group: "×42 Could not pass event ... to Essentials".
 *
 * Understands the vanilla/Fabric/Forge layout `[12:00:00] [thread/ERROR]:`,
 * Forge's `[20Oct2026 12:00:00.123] [thread/ERROR] [logger/]:` and
 * Paper/Spigot's `[12:00:00 ERROR]:`. A notice framed by `*****` lines and
 * logged line by line in the same second (Paper's "you are outdated") is
 * one entry, named by its first line of text.
 *
 * Other games (#91), by format:
 *  - oxide:   Oxide's and Carbon's plugin logs, `15:33 [Error] message`.
 *  - carbon:  Carbon's own log, `[2026.10.29 15:47:55] [ERRO] message`; an
 *             indented line under the same header continues the entry
 *             (a compile error's numbered messages).
 *  - bepinex: Valheim's BepInEx/LogOutput.log, `[Error  :Source] message`,
 *             where Source is the plugin that logged it. Unity's messages
 *             that every headless Valheim server logs are left out.
 * In the Rust formats an exception about a missing method, field or type
 * marks the group "framework": Rust changed under Oxide/Carbon (an update,
 * often on force-wipe day), so the plugin is probably fine.
 */
final class LogErrors
{
    /** Minecraft. */
    private const HEADER = '/^\[(?:\d{2}[A-Za-z]{3}\d{4} )?(\d{2}:\d{2}:\d{2})(?:\.\d+)?(?: (INFO|WARN|WARNING|ERROR|SEVERE|FATAL|DEBUG|TRACE))?\](?: \[([^\]]+?)\/(INFO|WARN|ERROR|FATAL|DEBUG|TRACE)\])?(?: \[[^\]]*\])?:? ?(.*)$/';

    private const OXIDE = '/^(\d{2}:\d{2}(?::\d{2})?) \[(Info|Warning|Error|Debug)\] ?(.*)$/';
    private const CARBON = '/^\[(?:\d{4}[.\-]\d{2}[.\-]\d{2} )?(\d{2}:\d{2}:\d{2})\] (?:\[(INFO|WARN|WARNING|ERRO|ERROR|CRIT|CRITICAL|FATAL|DEBUG|DBUG)\] )?(.*)$/i';
    private const BEPINEX = '/^\[(Message|Info|Warning|Error|Fatal|Debug)\s*:\s*([^\]]*?)\s*\] ?(.*)$/';

    /** Unity messages every dedicated (headless) Valheim server logs: not problems. */
    private const UNITY_HEADLESS = [
        'AsyncResourceUpload failed', 'custom render path shader', 'Could not find material Hidden/Video',
        'Could not find video decode shader', 'Failed to play intro cinematic', 'Missing audio clip',
        'HDR Render Texture not supported', '[AmplifyOcclusion]', 'not supported on the current platform',
        'Fallback handler could not load library',
    ];

    /** Sources in a BepInEx log that are the loader or the game, not a plugin. */
    private const BEPINEX_CORE = ['unity log', 'bepinex', 'harmonyx', 'harmony', 'console', 'preloader', 'detour'];

    /** Rust changed under the framework: the member a plugin (or Oxide) calls is gone. */
    public const MISSING_MEMBER = '/\b(MissingMethodException|MissingFieldException|MissingMemberException|TypeLoadException|Method not found|Field not found)\b/';

    /** Lines kept per stack trace, and groups returned. */
    private const MAX_DETAIL_LINES = 80;
    private const MAX_GROUPS = 200;

    /**
     * @param array<string, string> $knownPackages mod slug => normalised slug, for CrashParser's stack-trace match
     * @param 'minecraft'|'oxide'|'carbon'|'bepinex' $format
     *
     * @return list<array{key: string, level: string, count: int, first: string, last: string, message: string, exception: ?string, culprit: ?array{id: ?string, name: ?string, file: ?string, evidence: string, strength: string}, detail: string, framework: bool}>
     */
    public static function parse(string $log, bool $warnings = true, array $knownPackages = [], string $format = 'minecraft'): array
    {
        $groups = [];
        $entry = null;
        $n = 0;
        $banner = false;
        foreach (preg_split('/\r\n|\r|\n/', $log) ?: [] as $line) {
            if (($m = self::header($line, $format)) !== null) {
                $level = self::level($m['level']);
                $message = $m['message'];
                $rule = preg_match('/^[*=#~-]{5,}$/', $message) === 1;

                // Inside a framed notice: same second and level, until the closing rule.
                if ($banner && $entry !== null && $level === $entry['level'] && $m['time'] === $entry['time']) {
                    $entry['lines'][] = $line;
                    if ($entry['message'] === '' && ! $rule) {
                        $entry['message'] = $message;
                    }
                    if ($rule) {
                        $banner = false;
                    }
                    continue;
                }
                self::add($groups, $entry, $warnings, $knownPackages, $format);
                $banner = $rule && $level !== null && $format === 'minecraft';
                $entry = $level === null ? null
                    : ['time' => $m['time'], 'level' => $level, 'message' => $rule ? '' : $message, 'source' => $m['source'], 'header' => $line, 'lines' => [], 'n' => $n++];
                continue;
            }
            if ($entry !== null && trim($line) !== '' && count($entry['lines']) < 400) {
                $entry['lines'][] = rtrim($line);
            }
        }
        self::add($groups, $entry, $warnings, $knownPackages, $format);

        usort($groups, fn ($a, $b) => $b['order'] <=> $a['order']);

        return array_map(function ($g) {
            unset($g['order']);

            return $g;
        }, array_slice($groups, 0, self::MAX_GROUPS));
    }

    /**
     * Add a finished entry to its group (or start one).
     *
     * @param array<string, array<string, mixed>> $groups
     * @param array<string, mixed>|null $e
     * @param array<string, string> $knownPackages
     */
    private static function add(array &$groups, ?array $e, bool $warnings, array $knownPackages, string $format): void
    {
        if ($e === null || ($e['level'] === 'warn' && ! $warnings)) {
            return;
        }
        if ($format === 'bepinex' && strtolower($e['source']) === 'unity log' && self::headlessNoise($e['message'])) {
            return;
        }
        $exception = self::exception($e['lines'])
            // Oxide puts it in the message: "... (MissingMethodException: Method not found: ...)"
            ?? (preg_match('/\(((?:[\w.]+\.)?\w*(?:Exception|Error))(?:: (.*))?\)\s*$/', $e['message'], $x) ? $x[1] . (isset($x[2]) ? ': ' . $x[2] : '') : null)
            // BepInEx/Unity: "InvalidOperationException: test failure"
            ?? (preg_match('/^((?:[\w.]+\.)?\w+Exception)(?::|$)/', $e['message']) ? $e['message'] : null);
        $key = sha1($e['level'] . '|' . self::shape($e['message']) . '|' . ($exception === null ? '' : self::shape($exception)));
        $detail = implode("\n", array_slice(array_merge([$e['header']], $e['lines']), 0, self::MAX_DETAIL_LINES));
        $latest = [
            'message' => mb_substr($e['message'], 0, 400), 'exception' => $exception === null ? null : mb_substr($exception, 0, 300),
            'detail' => mb_substr($detail, 0, 16384), 'last' => $e['time'], 'order' => $e['n'],
        ];
        if (isset($groups[$key])) {
            // The newest occurrence is the one shown.
            $groups[$key] = ['count' => $groups[$key]['count'] + 1] + $latest + $groups[$key];

            return;
        }
        $groups[$key] = $latest + [
            'key' => $key, 'level' => $e['level'], 'count' => 1, 'first' => $e['time'],
            'culprit' => self::culprit($e['message'], $detail, $knownPackages, $format, $e['source'], $e['lines']),
            'framework' => in_array($format, ['oxide', 'carbon'], true) && preg_match(self::MISSING_MEMBER, $e['message'] . "\n" . ($exception ?? '')) === 1,
        ];
    }

    /**
     * Whether Rust probably changed under Oxide/Carbon: missing-member
     * errors from two or more plugins, or from any around force-wipe day
     * (the first Thursday of the month, when Rust updates). Then the
     * plugins aren't to blame until the framework catches up.
     *
     * @param list<array<string, mixed>> $groups from parse()
     *
     * @return array{plugins: list<string>, wipe_day: bool}|null
     */
    public static function frameworkBreak(array $groups, \DateTimeInterface $now): ?array
    {
        $plugins = [];
        $any = false;
        foreach ($groups as $g) {
            if ($g['framework'] ?? false) {
                $any = true;
                if (($g['culprit']['name'] ?? null) !== null) {
                    $plugins[$g['culprit']['name']] = true;
                }
            }
        }
        $firstThursday = (new \DateTimeImmutable($now->format('Y-m-01')))->modify('first thursday of this month');
        $days = (int) $firstThursday->diff(new \DateTimeImmutable($now->format('Y-m-d')))->format('%r%a');
        $wipeDay = $days >= 0 && $days <= 2;

        return $any && (count($plugins) >= 2 || $wipeDay) ? ['plugins' => array_keys($plugins), 'wipe_day' => $wipeDay] : null;
    }

    /**
     * A log line's header in the given format, or null for a continuation line.
     *
     * @return array{time: string, level: string, message: string, source: string}|null
     */
    private static function header(string $line, string $format): ?array
    {
        switch ($format) {
            case 'oxide':
                return preg_match(self::OXIDE, $line, $m) ? ['time' => $m[1], 'level' => $m[2], 'message' => trim($m[3]), 'source' => ''] : null;
            case 'carbon':
                if (! preg_match(self::CARBON, $line, $m) || preg_match('/^\s/', $m[3])) {
                    return null;
                }

                return ['time' => $m[1], 'level' => $m[2] !== '' ? $m[2] : 'INFO', 'message' => trim($m[3]), 'source' => ''];
            case 'bepinex':
                return preg_match(self::BEPINEX, $line, $m) ? ['time' => '', 'level' => $m[1], 'message' => trim($m[3]), 'source' => trim($m[2])] : null;
            default:
                return preg_match(self::HEADER, $line, $m)
                    ? ['time' => $m[1], 'level' => ($m[2] ?? '') !== '' ? $m[2] : ($m[4] ?? ''), 'message' => trim($m[5] ?? ''), 'source' => '']
                    : null;
        }
    }

    private static function headlessNoise(string $message): bool
    {
        foreach (self::UNITY_HEADLESS as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function level(string $level): ?string
    {
        return match (strtoupper($level)) {
            'ERROR', 'ERRO', 'SEVERE', 'FATAL', 'CRITICAL', 'CRIT' => 'error',
            'WARN', 'WARNING' => 'warn',
            default => null,
        };
    }

    /** A message with the parts that differ between repeats left out. */
    private static function shape(string $s): string
    {
        return (string) preg_replace(
            ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/0x[0-9a-f]+/i', '/@[0-9a-f]{4,}/i', '/-?\d+(\.\d+)?/'],
            ['<uuid>', '#', '@#', '#'],
            mb_substr($s, 0, 300),
        );
    }

    /** The exception behind a stack trace: the last "Caused by", else the first exception line. */
    private static function exception(array $lines): ?string
    {
        $first = null;
        $cause = null;
        foreach ($lines as $l) {
            $t = trim($l);
            if (str_starts_with($t, 'Caused by: ')) {
                $cause = substr($t, 11);
            } elseif ($first === null && preg_match('/^([a-zA-Z_$][\w$]*\.)+[\w$]*(Exception|Error|Throwable)\b/', $t)) {
                $first = $t;
            }
        }

        return $cause ?? $first;
    }

    /**
     * Who the entry points at: a plugin Paper names (prefix, event, task,
     * enabling), else what CrashParser finds (loader messages, stack frames).
     *
     * @param array<string, string> $knownPackages
     *
     * @return array{id: ?string, name: ?string, file: ?string, evidence: string, strength: string}|null
     */
    private static function culprit(string $message, string $detail, array $knownPackages, string $format = 'minecraft', string $source = '', array $lines = []): ?array
    {
        $named = fn (string $name, string $strength = 'strong') => ['id' => null, 'name' => $name, 'file' => null, 'evidence' => mb_substr($message, 0, 300), 'strength' => $strength];

        if ($format === 'oxide' || $format === 'carbon') {
            foreach ([
                '/^Error while compiling:? ([\w.]+)/',
                "/^Failed compiling '([\\w.]+?)(?:\\.cs)?'/",
                "/plugin '([^']+?)(?: v\\d[\\w.]*)?(?: by [^']*)?'/",
                "/ in '([^']+?)(?: v\\d[\\w.]*)?(?: by [^']*)?'/",
                '/^(?:Failed to (?:initialize|load|unload|compile)|Unable to load|Couldn\'t load) (?:plugin )?\'?([\w.]+?)\'?(?: v\d[\w.]*)?[ .:(]/',
                '/^\[([A-Za-z0-9 _.\-]{2,40})\] /',
            ] as $re) {
                if (preg_match($re, $message, $m) && ! in_array(strtolower($m[1]), ['csharp', 'oxide', 'carbon', 'rust', 'core'], true)) {
                    return $named(trim($m[1]));
                }
            }

            return null;
        }
        if ($format === 'bepinex') {
            if ($source !== '' && ! in_array(strtolower($source), self::BEPINEX_CORE, true)) {
                return $named($source);
            }
            if (preg_match('/^(?:Could not load|Error loading|Skipping) \[(.+?) \d[\w.]*\]/', $message, $m)) {
                return $named($m[1]);
            }
            // A Unity exception: the first stack frame outside the engine and the loader.
            foreach ($lines as $l) {
                if (preg_match('/^\s*(?:\(wrapper [^)]*\) )?([A-Za-z_][\w.+`<>]*)\.[\w<>`]+ \(/', $l, $m)
                    && ! preg_match('/^(UnityEngine|System|Mono|BepInEx|HarmonyLib|MonoMod)\b/', $m[1])) {
                    return ['id' => null, 'name' => preg_replace('/[+`<].*$/', '', $m[1]), 'file' => null, 'evidence' => 'in the stack trace: ' . trim($l), 'strength' => 'weak'];
                }
            }

            return null;
        }

        foreach ([
            '/Could not pass event \S+ to ([^\s]+) v/',
            '/(?:Plugin|Task #\d+ for) ([^\s]+) v\S+ generated an exception/',
            '/Error occurred while (?:enabling|disabling|loading) ([^\s(]+)/',
            '/^\[([A-Za-z0-9_.\-]{2,40})\] /',
        ] as $re) {
            if (preg_match($re, $message, $m) && ! in_array(strtolower($m[1]), ['stdout', 'stderr', 'server', 'minecraft'], true)) {
                return ['id' => null, 'name' => $m[1], 'file' => null, 'evidence' => mb_substr($message, 0, 300), 'strength' => 'strong'];
            }
        }
        $suspects = CrashParser::parse($detail, $knownPackages)['suspects'];

        return $suspects[0] ?? null;
    }
}
