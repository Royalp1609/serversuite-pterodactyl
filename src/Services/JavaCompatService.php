<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Models\ServerMod;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\JarJava;

/**
 * Refuses a mod or plugin build that needs a newer Java than the server runs
 * (#174). Without this Server Suite installed, say, WorldEdit 7.4.6 (Java 25)
 * on a Java 21 server: the Mods page said "installed" and Paper refused to
 * load it.
 *
 * The server's Java comes from its Docker image tag (java_21, java21, 21-jre,
 * temurin:21, ...). When either side is unknown the install goes ahead.
 */
class JavaCompatService
{
    public function __construct(protected JarJava $jars) {}

    /** The Java version a server's image runs, or null when the tag doesn't say. */
    public static function serverJava(object $server): ?int
    {
        $image = strtolower((string) ($server->image ?? ''));
        $tag = str_contains($image, ':') ? substr($image, strrpos($image, ':') + 1) : '';
        foreach (['/(?:^|[_\-.])java[_\-]?(\d{1,2})(?:$|[_\-.])/', '/^(?:java|jdk|jre)?(\d{1,2})(?:$|[_\-.])/'] as $re) {
            if (preg_match($re, $tag, $m) && (int) $m[1] >= 8) {
                return (int) $m[1];
            }
        }
        if (preg_match('/(?:temurin|openjdk|corretto|zulu|graalvm)[^:]*:(\d{1,2})\b/', $image, $m) && (int) $m[1] >= 8) {
            return (int) $m[1];
        }

        return null;
    }

    /** The Java a file needs (cached per URL for 30 days), or null when unknown. */
    public function required(ModVersion $version): ?int
    {
        if (! str_ends_with(strtolower($version->filename), '.jar') || trim($version->downloadUrl) === '') {
            return null;
        }
        $key = 'serversuite:jar-java:' . sha1($version->downloadUrl);
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached > 0 ? $cached : null;
        }
        $java = $this->jars->required($version->downloadUrl);
        Cache::put($key, $java ?? 0, now()->addDays($java === null ? 1 : 30));

        return $java;
    }

    /**
     * The Java each installed, enabled mod needs, for a version change that
     * may move the server to another Java image. Looks up the installed
     * version in its repository and reads its jar (both cached for 30 days
     * per mod version); unknown versions and jars are left out. Stops after
     * $budget seconds so a slow repository can't hold up a preflight.
     *
     * @return list<array{mod: string, java: int}> highest first
     */
    public function installedNeeds(object $server, int $budget = 20): array
    {
        if (! config('serversuite.java_check', true)) {
            return [];
        }
        $deadline = microtime(true) + $budget;
        $out = [];
        $mods = ServerMod::query()->where('server_uuid', $server->uuid)->whereNull('disabled_at')->get();
        foreach ($mods as $mod) {
            if (microtime(true) > $deadline) {
                break;
            }
            $need = $this->installedNeed($mod);
            if ($need !== null) {
                $out[] = ['mod' => (string) $mod->mod_name, 'java' => $need];
            }
        }
        usort($out, fn ($a, $b) => $b['java'] <=> $a['java']);

        return $out;
    }

    private function installedNeed(ServerMod $mod): ?int
    {
        $key = 'serversuite:mod-java:' . sha1($mod->source . '|' . $mod->source_id . '|' . $mod->installed_version);
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached > 0 ? $cached : null;
        }
        $need = null;
        try {
            $repos = app(RepositoryManager::class);
            if (in_array($mod->source, $repos->keys(), true)) {
                $versions = $repos->driver($mod->source)->versions((string) $mod->source_id);
                $match = null;
                foreach ($versions as $v) {
                    if ($v->versionNumber === $mod->installed_version || $v->versionId === $mod->installed_version) {
                        // Several files can share a version number; prefer the one on disk.
                        if ($match === null || $v->filename === $mod->target_filename) {
                            $match = $v;
                        }
                    }
                }
                $need = $match ? $this->required($match) : null;
            }
        } catch (\Throwable) {
            $need = null;
        }
        Cache::put($key, $need ?? 0, now()->addDays($need === null ? 1 : 30));

        return $need;
    }

    /** @throws DeliveryException when the build needs a newer Java than the server has */
    public function assertRuns(object $server, ModVersion $version, string $modName): void
    {
        if (! config('serversuite.java_check', true)) {
            return;
        }
        $have = self::serverJava($server);
        if ($have === null) {
            return;
        }
        $need = $this->required($version);
        if ($need !== null && $need > $have) {
            throw DeliveryException::needsNewerJava($modName, $version->versionNumber, $need, $have);
        }
    }
}
