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

    public function __construct(public readonly string $address, public readonly EdgeRouters $router) {}

    /** The configured edge, or null when edge mode is off or incomplete. */
    public static function fromConfig(): ?self
    {
        $c = (array) config('serversuite.subdomains.edge', []);
        $address = trim((string) ($c['address'] ?? ''));
        $routers = self::router();
        if (! ($c['enabled'] ?? false) || $address === '' || $routers === null) {
            return null;
        }

        return new self($address, $routers);
    }

    /**
     * The routers alone, for routes made earlier: set whenever a URL is,
     * even with edge mode switched off for new claims.
     */
    public static function router(): ?EdgeRouters
    {
        $c = (array) config('serversuite.subdomains.edge', []);
        $key = trim((string) ($c['api_key'] ?? ''));

        return EdgeRouters::fromList((string) ($c['router_url'] ?? ''), (int) ($c['timeout'] ?? 5), $key !== '' ? $key : null);
    }

    /** Where names without a route go (a holding server), or null: they're dropped. */
    public static function defaultBackend(): ?string
    {
        $b = trim((string) config('serversuite.subdomains.edge.default_backend', ''));

        return $b === '' ? null : $b;
    }

    /**
     * Per-node backend host (#2): node id or FQDN => the address the routers
     * reach that node on, for nodes whose allocations use public or
     * otherwise unroutable IPs. From the admin setting ("node = host" lines)
     * or a config array.
     *
     * @return array<string, string>
     */
    public static function nodeHosts(): array
    {
        $raw = config('serversuite.subdomains.edge.node_backends', []);
        if (is_array($raw)) {
            return array_change_key_case(array_map('strval', $raw), CASE_LOWER);
        }
        $out = [];
        foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
            if (preg_match('/^\s*([^=\s]+)\s*=\s*(\S+)\s*$/', $line, $m)) {
                $out[strtolower($m[1])] = $m[2];
            }
        }

        return $out;
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
        $overrides = self::nodeHosts();
        $nodeId = (string) ($alloc->node_id ?? $server->node_id ?? '');
        $nodeFqdn = strtolower(trim((string) ($server->node->fqdn ?? '')));
        if ($nodeId !== '' && isset($overrides[$nodeId])) {
            $host = $overrides[$nodeId];
        } elseif ($nodeFqdn !== '' && isset($overrides[$nodeFqdn])) {
            $host = $overrides[$nodeFqdn];
        }
        $host = trim($host, '[]');
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
        foreach (EdgeRouters::urls($routerUrl) as $url) {
            $p = parse_url($url);
            if (! is_array($p) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true) || ! isset($p['host']) || isset($p['user']) || isset($p['query'])) {
                return "Each router API URL must look like http://10.0.0.5:8080 ({$url} doesn't).";
            }
        }

        return null;
    }

    /** A default backend or node host setting check. Null when fine. */
    public static function invalidExtras(string $defaultBackend, string $nodeLines): ?string
    {
        $hostPort = '/^(\[[0-9a-f:]+\]|[a-z0-9.-]+):\d{1,5}$/i';
        if (trim($defaultBackend) !== '' && ! preg_match($hostPort, trim($defaultBackend))) {
            return 'The default backend must be host:port, such as 10.0.0.20:25565.';
        }
        foreach (preg_split('/\R/', $nodeLines) ?: [] as $i => $line) {
            if (trim($line) !== '' && ! preg_match('/^\s*[^=\s]+\s*=\s*(\[[0-9a-f:]+\]|[0-9a-f:]+|[a-z0-9.-]+)\s*$/i', $line)) {
                return 'Node addresses: line ' . ($i + 1) . ' must be "node id or FQDN = internal address", such as 3 = 10.0.0.12.';
            }
        }

        return null;
    }
}
