<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Exceptions\SubdomainException;
use ServerSuite\Models\Subdomain;
use ServerSuite\Support\MinecraftPing;

/**
 * Public status cards (#29): online state, players, MOTD, version and icon
 * of a subdomain whose owner switched its card on. Pings the server's live
 * allocation from the panel, at most once per cache window per subdomain,
 * however many people view it. Nothing panel-side (UUID, node, IP) leaves
 * this class.
 */
class StatusCardService
{
    /** Referenced by name so the package still loads where the panel isn't present (tests). */
    private const SERVER_MODEL = '\\Pterodactyl\\Models\\Server';

    /** @var (callable(string $host, int $port, string $hostname): array<string, mixed>)|null */
    private $pinger;

    /**
     * @param (callable(string $host, int $port, string $hostname): array<string, mixed>)|null $pinger
     */
    public function __construct(?callable $pinger = null)
    {
        $this->pinger = $pinger;
    }

    public static function enabled(): bool
    {
        return (bool) config('serversuite.status_cards.enabled', true);
    }

    /** The public subdomain behind a status URL, or null (off, unknown or disabled). */
    public function find(string $fqdn): ?Subdomain
    {
        if (! self::enabled()) {
            return null;
        }

        return Subdomain::query()->where('fqdn', strtolower(trim($fqdn, '.')))->where('status_public', true)->first();
    }

    /**
     * @return array{fqdn: string, online: bool, players: ?int, max: ?int, motd: string, version: string, icon: ?string, checked_at: string}
     */
    public function status(Subdomain $sub): array
    {
        return $this->live($sub->server_uuid, $sub->fqdn);
    }

    /** @return array{fqdn: ?string, online: bool, players: ?int, max: ?int, motd: string, version: string, icon: ?string, checked_at: string} */
    private function live(string $uuid, ?string $fqdn): array
    {
        $ttl = max(5, (int) config('serversuite.status_cards.cache_seconds', 30));

        return Cache::remember(self::cacheKey($uuid, $fqdn), $ttl, fn () => $this->check($uuid, $fqdn));
    }

    private static function cacheKey(string $uuid, ?string $fqdn): string
    {
        return "serversuite:status-card:{$uuid}:" . ($fqdn ?? '');
    }

    /** Public URL of a subdomain's card, on the panel's domain. */
    public static function url(string $fqdn, bool $badge = false): string
    {
        return url('/status/' . $fqdn . ($badge ? '/badge.svg' : ''));
    }

    /** @throws SubdomainException */
    public function setPublic(object $server, int $id, bool $public): Subdomain
    {
        $sub = Subdomain::query()->where('server_uuid', $server->uuid)->find($id)
            ?? throw new SubdomainException('Subdomain not found on this server.');
        // The card pings with the Minecraft protocol (#79); turning one off always works.
        if ($public && ! app(GameDetectionService::class)->for($server)->isMinecraft()) {
            throw new SubdomainException('Status cards only work for Minecraft servers so far.');
        }
        $sub->forceFill(['status_public' => $public])->save();
        Cache::forget(self::cacheKey($sub->server_uuid, $sub->fqdn));

        return $sub;
    }

    /** @return array{fqdn: ?string, online: bool, players: ?int, max: ?int, motd: string, version: string, icon: ?string, checked_at: string} */
    private function check(string $uuid, ?string $fqdn): array
    {
        $out = [
            'fqdn' => $fqdn, 'online' => false, 'players' => null, 'max' => null,
            'motd' => '', 'version' => '', 'icon' => null, 'checked_at' => now()->toIso8601String(),
        ];

        [$host, $port] = $this->target($uuid);
        if ($host === null || $port === null) {
            return $out;
        }

        // Without a subdomain the handshake names the address dialled.
        $hostname = $fqdn ?? $host;
        try {
            $status = $this->pinger !== null
                ? ($this->pinger)($host, $port, $hostname)
                : app(MinecraftPing::class)->ping($host, $port, $hostname);
        } catch (\Throwable) {
            return $out;
        }

        $icon = $status['favicon'] ?? null;

        return [
            'online'  => true,
            'players' => isset($status['players']['online']) ? (int) $status['players']['online'] : null,
            'max'     => isset($status['players']['max']) ? (int) $status['players']['max'] : null,
            'motd'    => mb_substr(trim(MinecraftPing::plainText($status['description'] ?? '')), 0, 200),
            'version' => mb_substr(MinecraftPing::plainText($status['version']['name'] ?? ''), 0, 40),
            // Only a real PNG data URI: anything else could carry markup.
            'icon'    => is_string($icon) && strlen($icon) < 65536 && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $icon) ? $icon : null,
        ] + $out;
    }

    /**
     * Where the panel reaches the server: the primary allocation's bind IP,
     * or the node's address when it binds every interface. Suspended or
     * missing servers have none.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public function target(string $uuid): array
    {
        if (! class_exists(self::SERVER_MODEL)) {
            return [null, null];
        }
        $model = self::SERVER_MODEL;
        $server = $model::query()->with(['allocation', 'node'])->where('uuid', $uuid)->first();
        $alloc = $server?->allocation;
        if (! $server || ! $alloc || $server->isSuspended()) {
            return [null, null];
        }

        $ip = (string) $alloc->ip;
        if (in_array($ip, ['', '0.0.0.0', '::'], true)) {
            $ip = (string) ($server->node->fqdn ?? '');
        }

        return [$ip !== '' ? $ip : null, (int) $alloc->port];
    }
}
