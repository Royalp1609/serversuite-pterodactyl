<?php

namespace ServerSuite\Services;

use Illuminate\Support\Carbon;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Support\CrashParser;

/**
 * Crash help after a mod change (#25). Remembers the last change per
 * server; if the server crashed (a crash report or a failed start in the
 * log written after that change), names the likely mod. Never acts on its
 * own: the Mods tab offers disable / restore the backup / view the report.
 */
class CrashHelpService
{
    /** How long after a change a crash is still blamed on it. */
    private const WINDOW_HOURS = 48;

    private const MAX_READ = 8 * 1048576;

    public function __construct(protected DaemonFileClient $daemon) {}

    /**
     * @param array{uuid?: ?string, name?: ?string}|null $backup the pre-change backup, if any
     */
    public function recordChange(string $serverUuid, string $label, ?array $backup = null): void
    {
        ModManagerSetting::forServer($serverUuid)->forceFill(['last_change' => [
            'at'     => now()->toIso8601String(),
            'label'  => mb_substr($label, 0, 200),
            'backup' => $backup && ! empty($backup['uuid']) ? ['uuid' => $backup['uuid'], 'name' => $backup['name'] ?? null] : null,
        ]])->save();
    }

    /**
     * The crash after the last change, or null.
     *
     * @return array{change: array, source: string, at: string, summary: ?string, suspects: array, report: string}|null
     */
    public function analyze(object $server): ?array
    {
        $change = ModManagerSetting::forServer($server->uuid)->last_change;
        if (! is_array($change) || ! empty($change['dismissed']) || empty($change['at'])) {
            return null;
        }
        $since = Carbon::parse($change['at']);
        if ($since->lt(now()->subHours(self::WINDOW_HOURS))) {
            return null;
        }

        $mods = ServerMod::forServer($server->uuid)->get();
        $packages = [];
        foreach ($mods as $m) {
            $packages[(string) ($m->mod_slug ?: $m->source_id)] = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($m->mod_slug ?: $m->mod_name)));
        }

        foreach ($this->evidence($server->uuid, $since) as [$path, $at]) {
            // The log's end is what matters, and it can be far bigger than a crash report.
            $text = str_ends_with($path, '.log')
                ? ($this->daemon->tailFile($server->uuid, $path, self::MAX_READ)['text'] ?? null)
                : $this->daemon->readFile($server->uuid, $path, self::MAX_READ);
            if ($text === null) {
                continue;
            }
            $parsed = CrashParser::parse($text, $packages);
            if (! $parsed['failed']) {
                continue;
            }

            return [
                'change'   => $change,
                'source'   => ltrim($path, '/'),
                'at'       => $at,
                'summary'  => $parsed['summary'],
                'suspects' => array_map(fn ($s) => $s + $this->match($s, $mods), $parsed['suspects']),
                'report'   => mb_substr($text, 0, 65536),
            ];
        }

        return null;
    }

    public function dismiss(string $serverUuid): void
    {
        $s = ModManagerSetting::forServer($serverUuid);
        $change = $s->last_change;
        if (is_array($change)) {
            $s->forceFill(['last_change' => ['dismissed' => true] + $change])->save();
        }
    }

    /** Rename the jar to .disabled (loaders skip it); enable() undoes it. */
    public function disable(ServerMod $mod): void
    {
        $dir = '/' . trim((string) ($mod->install_path ?: '/mods'), '/');
        $this->daemon->rename($mod->server_uuid, $dir, $mod->target_filename, $mod->target_filename . '.disabled');
        $mod->forceFill(['disabled_at' => now()])->save();
    }

    public function enable(ServerMod $mod): void
    {
        $dir = '/' . trim((string) ($mod->install_path ?: '/mods'), '/');
        $this->daemon->rename($mod->server_uuid, $dir, $mod->target_filename . '.disabled', $mod->target_filename);
        $mod->forceFill(['disabled_at' => null])->save();
    }

    /**
     * Crash reports newer than the change (newest first), then the log.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function evidence(string $uuid, Carbon $since): array
    {
        $out = [];
        foreach (['/crash-reports' => '/\.txt$/', '/logs' => '/^latest\.log$/'] as $dir => $pattern) {
            try {
                $entries = $this->daemon->listDirectory($uuid, $dir);
            } catch (\Throwable) {
                continue;
            }
            $fresh = collect($entries)
                ->filter(fn ($e) => $e['is_file'] && preg_match($pattern, (string) $e['name']) && $e['modified_at'] && Carbon::parse($e['modified_at'])->gt($since))
                ->sortByDesc(fn ($e) => Carbon::parse($e['modified_at'])->getTimestamp());
            foreach ($fresh as $e) {
                $out[] = [$dir . '/' . $e['name'], (string) $e['modified_at']];
            }
        }

        return $out;
    }

    /** @return array{server_mod_id: ?int, mod_name: ?string, disabled: bool} */
    public function match(array $suspect, $mods): array
    {
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
        $hit = $mods->first(function (ServerMod $m) use ($suspect, $norm) {
            if ($suspect['file'] && in_array($suspect['file'], [$m->target_filename, $m->target_filename . '.disabled'], true)) {
                return true;
            }
            foreach ([$suspect['id'], $suspect['name']] as $key) {
                if ($key && in_array($norm($key), array_filter([$norm($m->mod_slug), $norm($m->mod_name), $norm($m->source_id)]), true)) {
                    return true;
                }
            }

            return false;
        });

        return ['server_mod_id' => $hit?->id, 'mod_name' => $hit?->mod_name ?? $suspect['name'] ?? $suspect['id'] ?? $suspect['file'], 'disabled' => $hit?->disabled_at !== null];
    }
}
