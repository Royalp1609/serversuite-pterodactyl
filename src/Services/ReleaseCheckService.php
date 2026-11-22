<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Http;
use ServerSuite\Models\ModManagerGlobalSetting;

/**
 * Tells panel admins when a newer Server Suite release is out (#12).
 *
 * Once a day the scheduler asks GitHub's releases API for the newest
 * release. Only the version, URL and whether it is a security release are
 * kept; nothing about the panel is sent. A feed failure keeps the last known
 * result and never throws. Turned off, no request is made and no notice
 * shows. Never updates anything: the notice links to the release.
 */
class ReleaseCheckService
{
    public const FEED = 'https://api.github.com/repos/royalp1609/serversuite-pterodactyl/releases?per_page=30';

    private const ENABLED_KEY   = 'release_check_enabled';
    private const LATEST_KEY    = 'release_latest';
    private const DISMISSED_KEY = 'release_dismissed';
    private const NOTIFIED_KEY  = 'release_notified';

    /** The version this panel runs, from the package's own composer.json. */
    public function installedVersion(): string
    {
        $path = __DIR__ . '/../../composer.json';
        $json = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($json) && is_string($json['version'] ?? null) ? $json['version'] : '0.0.0';
    }

    public function enabled(): bool
    {
        return ModManagerGlobalSetting::getValue(self::ENABLED_KEY, '1') !== '0';
    }

    public function setEnabled(bool $on): void
    {
        ModManagerGlobalSetting::setValue(self::ENABLED_KEY, $on ? '1' : '0');
    }

    /**
     * Ask the feed and store the newest release that applies to this panel.
     * Returns what is stored afterwards (null: none known). Never throws.
     *
     * @return array{version: string, url: string, security: bool, checked_at: string}|null
     */
    public function check(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            $res = Http::timeout(5)->connectTimeout(3)
                ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ServerSuite-UpdateCheck'])
                ->get(self::FEED);
            if (! $res->successful() || ! is_array($res->json())) {
                logger()->info('[Server Suite] release check: feed unavailable', ['status' => $res->status()]);

                return $this->stored();
            }
            $newest = self::newest($res->json(), self::isPrerelease($this->installedVersion()));
        } catch (\Throwable $e) {
            logger()->info('[Server Suite] release check failed', ['error' => $e->getMessage()]);

            return $this->stored();
        }

        if ($newest === null) {
            return $this->stored();
        }
        $newest['checked_at'] = now()->toIso8601String();
        ModManagerGlobalSetting::setValue(self::LATEST_KEY, json_encode($newest));
        $this->notifyOnce($newest);

        return $newest;
    }

    /**
     * The notice for the admin page, or null when there is nothing to show:
     * check off, nothing newer than installed, or dismissed for this version.
     *
     * @return array{installed: string, version: string, url: string, security: bool}|null
     */
    public function notice(): ?array
    {
        if (! $this->enabled() || ($latest = $this->stored()) === null) {
            return null;
        }
        $installed = $this->installedVersion();
        if (self::compare($latest['version'], $installed) <= 0) {
            return null;
        }
        if (ModManagerGlobalSetting::getValue(self::DISMISSED_KEY) === $latest['version']) {
            return null;
        }

        return ['installed' => $installed, 'version' => $latest['version'], 'url' => $latest['url'], 'security' => $latest['security']];
    }

    /** Hide the notice until a release newer than the one shown comes out. */
    public function dismiss(): void
    {
        if (($latest = $this->stored()) !== null) {
            ModManagerGlobalSetting::setValue(self::DISMISSED_KEY, $latest['version']);
        }
    }

    /** @return array{version: string, url: string, security: bool, checked_at: string}|null */
    public function stored(): ?array
    {
        $raw = ModManagerGlobalSetting::getValue(self::LATEST_KEY);
        $d = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($d) || ! is_string($d['version'] ?? null) || self::parse($d['version']) === null) {
            return null;
        }

        return ['version' => $d['version'], 'url' => (string) ($d['url'] ?? ''), 'security' => (bool) ($d['security'] ?? false), 'checked_at' => (string) ($d['checked_at'] ?? '')];
    }

    /** Admin default targets (Discord / ntfy), once per newer version. */
    private function notifyOnce(array $newest): void
    {
        $installed = $this->installedVersion();
        if (self::compare($newest['version'], $installed) <= 0 || ModManagerGlobalSetting::getValue(self::NOTIFIED_KEY) === $newest['version']) {
            return;
        }
        ModManagerGlobalSetting::setValue(self::NOTIFIED_KEY, $newest['version']);
        $title = ($newest['security'] ? 'Security release: ' : '') . "Server Suite {$newest['version']} is out";
        $body = "This panel runs {$installed}. Release notes and downloads: {$newest['url']} (upgrade steps are in INSTALL.md).";
        app(Notifier::class)->notifyAdmin($title, $body, $newest['security'] ? 'error' : 'info', "release:{$newest['version']}");
    }

    /**
     * The highest release in a GitHub releases list. Drafts and tags that
     * aren't SemVer are skipped; pre-releases count only when the panel is on
     * one itself (someone on 1.0.0 isn't told about 1.1.0-rc.1).
     *
     * @return array{version: string, url: string, security: bool}|null
     */
    public static function newest(array $releases, bool $includePre): ?array
    {
        $best = null;
        foreach ($releases as $r) {
            if (! is_array($r) || ! empty($r['draft'])) {
                continue;
            }
            $v = ltrim((string) ($r['tag_name'] ?? ''), 'v');
            if (self::parse($v) === null || (! $includePre && self::isPrerelease($v))) {
                continue;
            }
            if ($best === null || self::compare($v, $best['version']) > 0) {
                $url = (string) ($r['html_url'] ?? '');
                $best = [
                    'version'  => $v,
                    'url'      => str_starts_with($url, 'https://github.com/') ? $url : '',
                    'security' => self::isSecurity($r),
                ];
            }
        }

        return $best;
    }

    /** A release marked as a security release: its name, or a line of its notes starting "Security release". */
    public static function isSecurity(array $release): bool
    {
        return stripos((string) ($release['name'] ?? ''), 'security') !== false
            || preg_match('/^[\s>*_#-]*security release\b/im', (string) ($release['body'] ?? '')) === 1;
    }

    public static function isPrerelease(string $v): bool
    {
        return (self::parse($v)['pre'] ?? []) !== [];
    }

    /** SemVer 2.0 precedence: <0, 0 or >0. Unparsable versions sort lowest. */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);
        if ($pa === null || $pb === null) {
            return ($pa === null ? 0 : 1) - ($pb === null ? 0 : 1);
        }
        foreach ([0, 1, 2] as $i) {
            if ($pa['core'][$i] !== $pb['core'][$i]) {
                return $pa['core'][$i] <=> $pb['core'][$i];
            }
        }
        // A version without a pre-release is higher than one with.
        if ($pa['pre'] === [] || $pb['pre'] === []) {
            return ($pa['pre'] === [] ? 1 : 0) - ($pb['pre'] === [] ? 1 : 0);
        }
        $n = max(count($pa['pre']), count($pb['pre']));
        for ($i = 0; $i < $n; $i++) {
            if (! isset($pa['pre'][$i]) || ! isset($pb['pre'][$i])) {
                return isset($pa['pre'][$i]) ? 1 : -1;
            }
            $x = $pa['pre'][$i];
            $y = $pb['pre'][$i];
            $xn = ctype_digit($x);
            $yn = ctype_digit($y);
            if ($xn && $yn) {
                $c = (int) $x <=> (int) $y;
            } elseif ($xn !== $yn) {
                $c = $xn ? -1 : 1; // numeric identifiers are lower
            } else {
                $c = strcmp($x, $y) <=> 0;
            }
            if ($c !== 0) {
                return $c;
            }
        }

        return 0;
    }

    /** @return array{core: array{int, int, int}, pre: list<string>}|null */
    private static function parse(string $v): ?array
    {
        if (! preg_match('/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/', trim($v), $m)) {
            return null;
        }

        return ['core' => [(int) $m[1], (int) $m[2], (int) $m[3]], 'pre' => isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : []];
    }
}
