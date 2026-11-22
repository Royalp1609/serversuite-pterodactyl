<?php

namespace ServerSuite\Edge;

use ServerSuite\Services\BedrockAddonService;
use ServerSuite\Support\Data\GameProfile;

/**
 * Edge mode settings (#2): which subdomains go through the hostname router,
 * what their DNS points at, and the route backend for a server.
 */
class EdgeMode
{
    /** The port players join on through the edge. */
    public const PORT = 25565;

    public function __construct(public readonly string $address, public readonly McRouterClient $router) {}

    /** The configured edge, or null when edge mode is off or incomplete. */
    public static function fromConfig(): ?self
    {
        $c = (array) config('serversuite.subdomains.edge', []);
        $address = trim((string) ($c['address'] ?? ''));
        $url = trim((string) ($c['router_url'] ?? ''));
        if (! ($c['enabled'] ?? false) || $address === '' || $url === '') {
            return null;
        }

        return new self($address, new McRouterClient($url, (int) ($c['timeout'] ?? 5)));
    }

    /**
     * The router alone, for routes made earlier: set whenever its URL is,
     * even with edge mode switched off for new claims.
     */
    public static function router(): ?McRouterClient
    {
        $c = (array) config('serversuite.subdomains.edge', []);
        $url = trim((string) ($c['router_url'] ?? ''));

        return $url === '' ? null : new McRouterClient($url, (int) ($c['timeout'] ?? 5));
    }

    /**
     * Only Java Edition sends the host name it connects to. Bedrock (and
     * PocketMine / Nukkit) use UDP without one, and other games have no
     * Minecraft handshake, so they keep direct records.
     */
    public static function appliesTo(object $server, GameProfile $game): bool
    {
        if (! $game->isMinecraft() || BedrockAddonService::isBedrock($server)) {
            return false;
        }
        $hint = strtolower(($server->egg->name ?? '') . ' ' . ($server->startup ?? ''));

        return ! preg_match('/pocketmine|nukkit|bedrock_server/', $hint);
    }

    /**
     * Where the router sends the server's players: the allocation's own IP
     * (not its alias, which is the public address players used before) and
     * port. An allocation on every interface (0.0.0.0) uses the node's FQDN.
     */
    public static function backend(object $server): ?string
    {
        $alloc = $server->allocation ?? null;
        if (! $alloc || ! isset($alloc->port)) {
            return null;
        }
        $host = trim((string) ($alloc->ip ?? ''));
        if ($host === '' || $host === '0.0.0.0' || $host === '::') {
            $host = trim((string) ($server->node->fqdn ?? ''));
        }
        if ($host === '') {
            return null;
        }

        return (str_contains($host, ':') ? "[{$host}]" : $host) . ':' . (int) $alloc->port;
    }

    /**
     * Admin setting checks: the public address is an IP or host name, the
     * router URL is http(s) without credentials. Null when fine.
     */
    public static function invalid(string $address, string $routerUrl): ?string
    {
        $address = trim($address);
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP) === false
            && ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/i', $address)) {
            return 'The edge address must be an IP address or a host name, without a port.';
        }
        $routerUrl = trim($routerUrl);
        if ($routerUrl !== '') {
            $p = parse_url($routerUrl);
            if (! is_array($p) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || ! isset($p['host']) || isset($p['user']) || isset($p['query'])) {
                return 'The router API URL must look like http://10.0.0.5:8080.';
            }
        }

        return null;
    }
}
