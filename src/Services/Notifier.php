<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Jobs\SendNotificationJob;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ModManagerGlobalSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Notifications (#26): Server Suite events to Discord webhooks and ntfy
 * topics. A server uses its own targets, or the admin default when it has
 * none. Each event can be switched off. Repeats are held back (the same
 * message within the event's cooldown, and at most 30 a server an hour), so
 * a crash loop or a failing backup doesn't flood a channel. Sending happens
 * in a queued, encrypted job; the URLs and tokens are secrets.
 *
 * Targets an owner types in must point at the public internet: the panel
 * makes the request, so an internal address would let a user probe the
 * panel's network. The admin default may point anywhere.
 */
class Notifier
{
    public const EVENTS = [
        'updates'   => 'Mod updates available',
        'backup'    => 'A scheduled backup failed or was skipped',
        'crash'     => 'The server crashed after a mod change',
        'switch'    => 'A version or loader switch started or failed',
        'autoupgrade' => 'An automatic version upgrade finished, failed or is on hold',
        'subdomain' => 'A subdomain was claimed or released',
    ];

    /** Minutes before the same message for an event is sent again. */
    private const COOLDOWN = ['updates' => 1440, 'backup' => 360, 'crash' => 60, 'switch' => 1, 'autoupgrade' => 1, 'subdomain' => 1];

    private const HOURLY_CAP = 30;
    private const DEFAULT_KEY = 'notify.default';
    private const DISCORD = '#^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/webhooks/\d+/[\w-]+$#';

    /**
     * Queue an event for a server's targets. Never throws.
     */
    public function notify(string $serverUuid, string $event, string $title, string $body = '', string $level = 'info', ?string $fingerprint = null): void
    {
        try {
            $cfg = $this->forServer($serverUuid);
            if ($cfg['targets'] === [] || ! ($cfg['events'][$event] ?? true)) {
                return;
            }
            $minutes = self::COOLDOWN[$event] ?? 10;
            if (! Cache::add("serversuite:notify:{$serverUuid}:{$event}:" . sha1($fingerprint ?? $title . $body), 1, now()->addMinutes($minutes))) {
                return;
            }
            $hour = "serversuite:notify:{$serverUuid}:hour:" . now()->format('YmdH');
            Cache::add($hour, 0, now()->addHours(2));
            if (Cache::increment($hour) > self::HOURLY_CAP) {
                return;
            }

            SendNotificationJob::dispatch($cfg['targets'], $this->message($serverUuid, $title, $body, $level), $cfg['own']);
        } catch (\Throwable $e) {
            logger()->warning('[Server Suite] notification not queued', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * What a server sends to: its own settings, else the admin default.
     *
     * @return array{targets: list<array<string, string>>, events: array<string, bool>, own: bool}
     */
    public function forServer(string $serverUuid): array
    {
        $own = ModManagerSetting::query()->where('server_uuid', $serverUuid)->first()?->notify;
        if (is_array($own) && ($own['targets'] ?? []) !== []) {
            return ['targets' => $own['targets'], 'events' => (array) ($own['events'] ?? []), 'own' => true];
        }
        $def = $this->defaults();

        // A server's own event switches still apply to the admin's targets.
        return ['targets' => $def['targets'], 'events' => (array) ($own['events'] ?? $def['events']), 'own' => false];
    }

    /** @return array{targets: list<array<string, string>>, events: array<string, bool>} */
    public function defaults(): array
    {
        $raw = null;
        try {
            $enc = ModManagerGlobalSetting::getValue(self::DEFAULT_KEY);
            $raw = is_string($enc) && $enc !== '' ? Crypt::decryptString($enc) : null;
        } catch (\Throwable) {
            // APP_KEY rotated: the defaults are gone
        }
        $d = $raw === null ? null : json_decode($raw, true);

        return ['targets' => (array) ($d['targets'] ?? []), 'events' => (array) ($d['events'] ?? [])];
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $events
     *
     * @throws \InvalidArgumentException
     */
    public function saveServer(string $serverUuid, array $targets, array $events): void
    {
        ModManagerSetting::forServer($serverUuid)->forceFill([
            'notify' => ['targets' => $this->clean($targets, true), 'events' => $this->cleanEvents($events)],
        ])->save();
    }

    /**
     * @param list<array<string, mixed>> $targets
     * @param array<string, mixed> $events
     *
     * @throws \InvalidArgumentException
     */
    public function saveDefaults(array $targets, array $events): void
    {
        ModManagerGlobalSetting::setValue(self::DEFAULT_KEY, Crypt::encryptString((string) json_encode(['targets' => $this->clean($targets, false), 'events' => $this->cleanEvents($events)])));
    }

    /**
     * Send a test message now and report each target.
     *
     * @param list<array<string, mixed>> $targets
     *
     * @return list<array{label: string, ok: bool, error: ?string}>
     */
    public function test(array $targets, bool $userSupplied, string $serverUuid = ''): array
    {
        $out = [];
        $msg = $this->message($serverUuid, 'Test from Server Suite', 'If you can read this, notifications reach this channel.', 'info');
        foreach ($this->clean($targets, $userSupplied) as $t) {
            try {
                self::deliver($t, $msg, $userSupplied);
                $out[] = ['label' => self::label($t), 'ok' => true, 'error' => null];
            } catch (\Throwable $e) {
                $out[] = ['label' => self::label($t), 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $out;
    }

    /**
     * POST one message to one target.
     *
     * @param array<string, string> $t
     * @param array{title: string, body: string, level: string, server: ?string, url: ?string} $msg
     *
     * @throws \RuntimeException
     */
    public static function deliver(array $t, array $msg, bool $userSupplied): void
    {
        $http = Http::timeout(8)->withoutRedirecting()->withUserAgent('ServerSuite');
        if ($t['type'] === 'discord') {
            $r = $http->post($t['url'], [
                'username' => 'Server Suite',
                'allowed_mentions' => ['parse' => []],
                'embeds' => [array_filter([
                    'title' => mb_substr($msg['title'], 0, 250),
                    'description' => mb_substr($msg['body'], 0, 3500),
                    'color' => ['error' => 0xEF4444, 'warn' => 0xF59E0B][$msg['level']] ?? 0x6366F1,
                    'url' => $msg['url'],
                    'footer' => $msg['server'] ? ['text' => mb_substr($msg['server'], 0, 200)] : null,
                ])],
            ]);
        } else {
            $url = rtrim($t['server'], '/') . '/' . rawurlencode($t['topic']);
            if ($userSupplied) {
                self::assertPublic($url);
            }
            $headers = array_filter([
                'Title' => self::headerText(($msg['server'] ? $msg['server'] . ': ' : '') . $msg['title']),
                'Priority' => ['error' => '4', 'warn' => '3'][$msg['level']] ?? '3',
                'Tags' => ['error' => 'rotating_light', 'warn' => 'warning'][$msg['level']] ?? 'information_source',
                'Click' => $msg['url'],
                'Authorization' => ($t['token'] ?? '') !== '' ? 'Bearer ' . $t['token'] : null,
            ]);
            $r = $http->withHeaders($headers)->withBody($msg['body'] !== '' ? $msg['body'] : $msg['title'], 'text/plain')->post($url);
        }
        if (! $r->successful()) {
            throw new \RuntimeException('HTTP ' . $r->status() . ($r->status() === 404 ? ' (check the URL or topic)' : ''));
        }
    }

    /** @param array<string, string> $t */
    public static function label(array $t): string
    {
        return $t['type'] === 'discord'
            ? 'Discord webhook …' . substr($t['url'], -6)
            : 'ntfy ' . (parse_url($t['server'], PHP_URL_HOST) ?: $t['server']) . '/' . $t['topic'];
    }

    // -------------------------------------------------------------------------

    /** @return array{title: string, body: string, level: string, server: ?string, url: ?string} */
    private function message(string $serverUuid, string $title, string $body, string $level): array
    {
        $model = '\\Pterodactyl\\Models\\Server';
        $server = $serverUuid !== '' && class_exists($model) ? $model::query()->where('uuid', $serverUuid)->first() : null;
        $url = null;
        try {
            $url = $server ? url('/server/' . ($server->uuidShort ?? substr((string) $server->uuid, 0, 8))) : null;
        } catch (\Throwable) {
        }

        return ['title' => $title, 'body' => $body, 'level' => $level, 'server' => $server?->name, 'url' => $url];
    }

    /**
     * Validate and normalise targets.
     *
     * @param list<array<string, mixed>> $targets
     *
     * @return list<array<string, string>>
     *
     * @throws \InvalidArgumentException
     */
    private function clean(array $targets, bool $userSupplied): array
    {
        $out = [];
        foreach (array_slice($targets, 0, 5) as $t) {
            $type = (string) ($t['type'] ?? '');
            if ($type === 'discord') {
                $url = trim((string) ($t['url'] ?? ''));
                if (! preg_match(self::DISCORD, $url)) {
                    throw new \InvalidArgumentException('A Discord webhook URL looks like https://discord.com/api/webhooks/123/abc.');
                }
                $out[] = ['type' => 'discord', 'url' => $url];
            } elseif ($type === 'ntfy') {
                $server = rtrim(trim((string) ($t['server'] ?? '')) ?: 'https://ntfy.sh', '/');
                $topic = trim((string) ($t['topic'] ?? ''));
                if (! preg_match('#^https?://[^\s/?\#]+(/[^\s?\#]*)?$#', $server) || ($userSupplied && ! str_starts_with($server, 'https://'))) {
                    throw new \InvalidArgumentException('The ntfy server must be an https:// address' . ($userSupplied ? '' : ' (or http:// on your own network)') . '.');
                }
                if (! preg_match('/^[\w-]{1,64}$/', $topic)) {
                    throw new \InvalidArgumentException('An ntfy topic is 1-64 letters, digits, - or _.');
                }
                if ($userSupplied) {
                    self::assertPublic($server);
                }
                $out[] = ['type' => 'ntfy', 'server' => $server, 'topic' => $topic, 'token' => trim((string) ($t['token'] ?? ''))];
            } else {
                throw new \InvalidArgumentException('Pick Discord or ntfy for each target.');
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $events
     *
     * @return array<string, bool>
     */
    private function cleanEvents(array $events): array
    {
        $out = [];
        foreach (array_keys(self::EVENTS) as $e) {
            $out[$e] = (bool) ($events[$e] ?? true);
        }

        return $out;
    }

    /**
     * Refuse a URL whose host resolves to a private, loopback, link-local
     * or reserved address. Checked when saved and again when sending.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertPublic(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
            array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
        if ($ips === []) {
            throw new \InvalidArgumentException("{$host} doesn't resolve.");
        }
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \InvalidArgumentException("{$host} is on a private network. Ask a panel admin to set it as the default instead.");
            }
        }
    }

    /** HTTP headers are Latin-1: keep a title readable without breaking the request. */
    private static function headerText(string $s): string
    {
        $s = (string) preg_replace('/[\r\n]+/', ' ', $s);

        return mb_check_encoding($s, 'ASCII') ? mb_substr($s, 0, 200) : '=?UTF-8?B?' . base64_encode(mb_substr($s, 0, 120)) . '?=';
    }
}
