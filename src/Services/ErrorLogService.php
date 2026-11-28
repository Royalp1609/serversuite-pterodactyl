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
     * Acknowledge what the Errors tab shows now, or one item of it: the log's
     * error and warning groups (by key, the time each was first seen and its
     * count) and crash reports (by file name). Each is "acknowledged" until it
     * happens again. With $groupKey or $crash only that one item is added;
     * with neither, everything listed now is.
     *
     * @param list<array{path: string, format: string, newest?: string}>|null $sources
     */
    public function acknowledge(string $serverUuid, ?array $sources = null, ?string $groupKey = null, ?string $crash = null): void
    {
        $this->forget($serverUuid);
        $report = $this->report($serverUuid, true, $sources);
        $ack = $this->ackState($serverUuid, $report);
        $one = $groupKey !== null || $crash !== null;

        foreach ($report['groups'] as $g) {
            if (! $one || (string) $g['key'] === $groupKey) {
                $ack['groups'][(string) $g['key']] = ['first' => $g['first'] ?? null, 'count' => (int) $g['count']];
            }
        }
        foreach ($report['crashes'] ?? [] as $c) {
            if (! $one || (string) $c['name'] === $crash) {
                $ack['crashes'][(string) $c['name']] = true;
            }
        }
        if (! $one) {
            $ack['at'] = now()->toIso8601String();
        }

        $this->saveAck($serverUuid, $ack);
    }

    /**
     * Forget an acknowledge: one group or crash report, or with neither
     * everything, so it all shows again.
     *
     * @param list<array{path: string, format: string, newest?: string}>|null $sources
     */
    public function unacknowledge(string $serverUuid, ?string $groupKey = null, ?string $crash = null, ?array $sources = null): void
    {
        if ($groupKey === null && $crash === null) {
            ModManagerSetting::forServer($serverUuid)->forceFill(['errors_ack' => null])->save();

            return;
        }

        $this->forget($serverUuid);
        $ack = $this->ackState($serverUuid, $this->report($serverUuid, true, $sources));
        if ($groupKey !== null) {
            unset($ack['groups'][$groupKey]);
        }
        if ($crash !== null) {
            unset($ack['crashes'][$crash]);
        }
        $this->saveAck($serverUuid, $ack);
    }

    /**
     * The stored acknowledge as {at, groups, crashes}. The first format (before
     * per-item acknowledge) kept crash reports as "everything written before
     * `at`": that is turned into names here, from the report, so one of them
     * can be un-acknowledged on its own.
     *
     * @param  array<string, mixed> $report
     * @return array{at: ?string, groups: array<string, array{first: ?string, count: int}>, crashes: array<string, bool>}
     */
    private function ackState(string $serverUuid, array $report): array
    {
        $raw = ModManagerSetting::forServer($serverUuid)->errors_ack;
        $raw = is_array($raw) ? $raw : [];
        $ack = [
            'at' => isset($raw['at']) ? (string) $raw['at'] : null,
            'groups' => is_array($raw['groups'] ?? null) ? $raw['groups'] : [],
            'crashes' => is_array($raw['crashes'] ?? null) ? $raw['crashes'] : [],
        ];
        if (! isset($raw['crashes']) && $ack['at'] !== null) {
            $at = strtotime($ack['at']);
            foreach ($report['crashes'] ?? [] as $c) {
                $t = isset($c['at']) ? strtotime((string) $c['at']) : false;
                if ($at !== false && $t !== false && $t <= $at) {
                    $ack['crashes'][(string) $c['name']] = true;
                }
            }
        }

        return $ack;
    }

    /** @param array{at: ?string, groups: array<string, mixed>, crashes: array<string, bool>} $ack */
    private function saveAck(string $serverUuid, array $ack): void
    {
        $empty = $ack['groups'] === [] && $ack['crashes'] === [];
        ModManagerSetting::forServer($serverUuid)->forceFill(['errors_ack' => $empty ? null : [
            'at' => $ack['at'],
            'groups' => array_slice($ack['groups'], -500, null, true),
            'crashes' => array_slice($ack['crashes'], -200, null, true),
        ]])->save();
    }

    /**
     * Mark a report's groups and crashes as acknowledged or not. A group is
     * acknowledged while it is the same one (same key and first-seen time, so
     * a restarted server's new log counts as new) and hasn't happened again
     * since. A crash report is acknowledged by its file name (the first
     * format: when it was written before the acknowledge).
     *
     * @param  array<string, mixed> $report from report()
     * @return array<string, mixed> the report plus acknowledged flags, an `ack` block and `new_count`
     */
    public function withAck(string $serverUuid, array $report): array
    {
        $raw = ModManagerSetting::forServer($serverUuid)->errors_ack;
        $ack = is_array($raw) && (! empty($raw['groups']) || ! empty($raw['crashes']) || ! empty($raw['at'])) ? $this->ackState($serverUuid, $report) : null;
        $new = 0;
        $acked = 0;

        foreach ($report['groups'] ?? [] as $i => $g) {
            $seen = $ack['groups'][(string) $g['key']] ?? null;
            $done = $seen !== null && ($seen['first'] ?? null) === ($g['first'] ?? null) && (int) $g['count'] <= (int) ($seen['count'] ?? 0);
            $report['groups'][$i]['acknowledged'] = $done;
            $done ? $acked++ : $new++;
        }
        foreach ($report['crashes'] ?? [] as $i => $c) {
            $done = $ack !== null && ! empty($ack['crashes'][(string) $c['name']]);
            $report['crashes'][$i]['acknowledged'] = $done;
            $done ? $acked++ : $new++;
        }

        return $report + ['ack' => $ack && $acked > 0 ? ['at' => $ack['at']] : null, 'new_count' => $new];
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
