<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Support\CrashParser;
use ServerSuite\Support\LogErrors;

/**
 * The Errors tab (#87; Pelican #41/#91): the warnings and errors in the end
 * of logs/latest.log, grouped, with the plugin or mod each points at, plus
 * the server's recent crash reports. Read through Wings; nothing touches
 * Pterodactyl's console. Cached for a few seconds, so a polling page and a
 * second viewer don't re-read the log every time.
 *
 * Other games (#91) pass their log sources (GameDetectionService::
 * logSources()): Oxide's or Carbon's log for Rust, BepInEx's for Valheim,
 * each parsed in its own format. Rust also gets a notice when the errors
 * look like Rust changed under the framework rather than a broken plugin.
 */
class ErrorLogService
{
    public const LOG = '/logs/latest.log';

    private const CRASH_DIR = '/crash-reports';
    private const MAX_CRASH_READ = 1048576;

    public function __construct(protected DaemonFileClient $daemon, protected CrashHelpService $crashHelp) {}

    /**
     * @param list<array{path: string, format: string, newest?: string}>|null $sources null = Minecraft's logs/latest.log
     *
     * @return array{found: bool, size: int, partial: bool, files: list<string>, groups: list<array<string, mixed>>, crashes: list<array{name: string, at: ?string}>, notice: ?array{plugins: list<string>, wipe_day: bool}}
     */
    public function report(string $serverUuid, bool $warnings, ?array $sources = null): array
    {
        $ttl = max(1, (int) config('serversuite.errors.cache_seconds', 10));
        $sources ??= [['path' => self::LOG, 'format' => 'minecraft']];

        $version = (int) Cache::get("serversuite:errors-v:{$serverUuid}", 0);

        return Cache::remember("serversuite:errors:{$serverUuid}:{$version}:" . (int) $warnings . ':' . md5((string) json_encode($sources)), $ttl,
            fn () => $this->build($serverUuid, $warnings, $sources));
    }

    /** Drop the cached reports for a server (every source set): the next read re-reads the logs. */
    public function forget(string $serverUuid): void
    {
        Cache::forever("serversuite:errors-v:{$serverUuid}", (int) Cache::get("serversuite:errors-v:{$serverUuid}", 0) + 1);
    }

    /**
     * One crash report, by a name from report()'s list, with its suspects.
     *
     * @return array{name: string, summary: ?string, suspects: list<array<string, mixed>>, text: string}|null
     */
    public function crash(string $serverUuid, string $name): ?array
    {
        if (! preg_match('/^[\w.\-]+\.txt$/', $name)) {
            return null;
        }
        $listed = array_column($this->crashes($serverUuid), 'name');
        if (! in_array($name, $listed, true)) {
            return null;
        }
        $text = $this->daemon->readFile($serverUuid, self::CRASH_DIR . '/' . $name, self::MAX_CRASH_READ);
        if ($text === null) {
            return null;
        }
        $mods = ServerMod::forServer($serverUuid)->get();
        $parsed = CrashParser::parse($text, $this->packages($mods));

        return [
            'name' => $name,
            'summary' => $parsed['summary'],
            'suspects' => array_map(fn ($s) => $s + $this->crashHelp->match($s, $mods), $parsed['suspects']),
            'text' => mb_substr($text, 0, 65536),
        ];
    }

    /**
     * @param list<array{path: string, format: string, newest?: string}> $sources
     *
     * @return array{found: bool, size: int, partial: bool, files: list<string>, groups: list<array<string, mixed>>, crashes: list<array{name: string, at: ?string}>, notice: ?array{plugins: list<string>, wipe_day: bool}}
     */
    private function build(string $serverUuid, bool $warnings, array $sources): array
    {
        $bytes = max(65536, (int) config('serversuite.errors.tail_bytes', 1048576));
        $mods = ServerMod::forServer($serverUuid)->get();
        $groups = [];
        $files = [];
        $size = 0;
        $partial = false;
        $rust = false;
        foreach ($sources as $source) {
            $path = $this->resolve($serverUuid, $source);
            $tail = $path === null ? null : $this->daemon->tailFile($serverUuid, $path, $bytes);
            if ($tail === null) {
                continue;
            }
            $files[] = ltrim((string) $path, '/');
            $size += (int) $tail['size'];
            $partial = $partial || $tail['size'] > $bytes;
            $text = $tail['text'];
            if ($tail['size'] > strlen($text)) {
                $text = (string) substr($text, (int) strpos($text, "\n") + 1);   // drop the cut-off first line
            }
            $rust = $rust || in_array($source['format'], ['oxide', 'carbon'], true);
            foreach (LogErrors::parse($text, $warnings, $this->packages($mods), $source['format']) as $g) {
                $g['mod'] = $g['culprit'] === null ? null : $this->crashHelp->match($g['culprit'], $mods);
                $groups[] = $g;
            }
        }

        return [
            'found' => $files !== [],
            'size' => $size,
            'partial' => $partial,
            'files' => $files,
            'groups' => $groups,
            'crashes' => $this->crashes($serverUuid),
            'notice' => $rust ? LogErrors::frameworkBreak($groups, now()) : null,
        ];
    }

    /** A source's file: the path itself, or the newest matching file in that folder. */
    private function resolve(string $serverUuid, array $source): ?string
    {
        if (! isset($source['newest'])) {
            return (string) $source['path'];
        }
        try {
            $entries = $this->daemon->listDirectory($serverUuid, (string) $source['path']);
        } catch (\Throwable) {
            return null;
        }
        // Newest first; same time: the later name (oxide_2026-10-29 after oxide_2026-10-28).
        $newest = collect($entries)
            ->filter(fn ($e) => $e['is_file'] && preg_match((string) $source['newest'], (string) $e['name']))
            ->sort(fn ($a, $b) => [(int) strtotime((string) ($b['modified_at'] ?? '')), (string) $b['name']] <=> [(int) strtotime((string) ($a['modified_at'] ?? '')), (string) $a['name']])
            ->first();

        return $newest === null ? null : rtrim((string) $source['path'], '/') . '/' . $newest['name'];
    }

    /** @return list<array{name: string, at: ?string}> newest first, at most 10 */
    private function crashes(string $serverUuid): array
    {
        try {
            $entries = $this->daemon->listDirectory($serverUuid, self::CRASH_DIR);
        } catch (\Throwable) {
            return [];
        }

        return collect($entries)
            ->filter(fn ($e) => $e['is_file'] && str_ends_with((string) $e['name'], '.txt'))
            ->sortByDesc(fn ($e) => (int) strtotime((string) ($e['modified_at'] ?? '')))
            ->take(10)
            ->map(fn ($e) => ['name' => (string) $e['name'], 'at' => $e['modified_at'] ?? null])
            ->values()->all();
    }

    /**
     * Acknowledge what the Errors tab shows now: the log's error and warning
     * groups (by key, the time each was first seen and its count) and the
     * crash reports up to now. Each is "acknowledged" until it happens again.
     *
     * @param list<array{path: string, format: string, newest?: string}>|null $sources
     */
    public function acknowledge(string $serverUuid, ?array $sources = null): void
    {
        $this->forget($serverUuid);
        $report = $this->report($serverUuid, true, $sources);
        $groups = [];
        foreach ($report['groups'] as $g) {
            $groups[(string) $g['key']] = ['first' => $g['first'] ?? null, 'count' => (int) $g['count']];
        }
        ModManagerSetting::forServer($serverUuid)->forceFill(['errors_ack' => [
            'at' => now()->toIso8601String(),
            'groups' => array_slice($groups, 0, 500, true),
        ]])->save();
    }

    /** Forget the acknowledge: everything shows again. */
    public function unacknowledge(string $serverUuid): void
    {
        ModManagerSetting::forServer($serverUuid)->forceFill(['errors_ack' => null])->save();
    }

    /**
     * Mark a report's groups and crashes as acknowledged or not. A group is
     * acknowledged while it is the same one (same key and first-seen time, so
     * a restarted server's new log counts as new) and hasn't happened again
     * since. A crash is acknowledged when it was written before the acknowledge.
     *
     * @param  array<string, mixed> $report from report()
     * @return array<string, mixed> the report plus acknowledged flags, an `ack` block and `new_count`
     */
    public function withAck(string $serverUuid, array $report): array
    {
        $ack = ModManagerSetting::forServer($serverUuid)->errors_ack;
        $ack = is_array($ack) && ! empty($ack['at']) ? $ack : null;
        $at = $ack ? strtotime((string) $ack['at']) : null;
        $new = 0;

        foreach ($report['groups'] ?? [] as $i => $g) {
            $seen = $ack['groups'][(string) $g['key']] ?? null;
            $done = $seen !== null && ($seen['first'] ?? null) === ($g['first'] ?? null) && (int) $g['count'] <= (int) ($seen['count'] ?? 0);
            $report['groups'][$i]['acknowledged'] = $done;
            $new += $done ? 0 : 1;
        }
        foreach ($report['crashes'] ?? [] as $i => $c) {
            $t = isset($c['at']) ? strtotime((string) $c['at']) : false;
            $done = $at !== null && $t !== false && $t <= $at;
            $report['crashes'][$i]['acknowledged'] = $done;
            $new += $done ? 0 : 1;
        }

        return $report + ['ack' => $ack ? ['at' => $ack['at']] : null, 'new_count' => $new];
    }

    /** @return array<string, string> */
    private function packages($mods): array
    {
        $out = [];
        foreach ($mods as $m) {
            $out[(string) ($m->mod_slug ?: $m->source_id)] = (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) ($m->mod_slug ?: $m->mod_name)));
        }

        return $out;
    }
}
