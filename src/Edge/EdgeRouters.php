<?php

namespace ServerSuite\Edge;

/**
 * Every edge router (#2): one mc-router, or several behind the edge address
 * for high availability. Routes go to all of them. mc-router instances don't
 * share routes, so a router that was down or restarted is brought back in
 * line by serversuite:edge-sync, which checks each one on its own.
 */
final class EdgeRouters
{
    /** @param list<McRouterClient> $routers */
    public function __construct(public readonly array $routers) {}

    /**
     * The router API URLs in a setting: one per line or comma separated.
     *
     * @return list<string>
     */
    public static function urls(string $list): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $list) ?: []), fn ($u) => $u !== '')));
    }

    public static function fromList(string $list, int $timeout = 5, ?string $apiKey = null): ?self
    {
        $urls = self::urls($list);

        return $urls === [] ? null : new self(array_map(fn ($u) => new McRouterClient($u, $timeout, $apiKey), $urls));
    }

    /**
     * Point a name at a backend on every router. Fine when at least one took
     * it (edge-sync fixes the rest); throws when none did.
     *
     * @throws \RuntimeException with a message safe to show the admin
     */
    public function put(string $fqdn, string $backend): void
    {
        $failed = [];
        foreach ($this->routers as $r) {
            try {
                $r->put($fqdn, $backend);
            } catch (\RuntimeException $e) {
                $failed[] = $e;
            }
        }
        if ($failed !== [] && count($failed) === count($this->routers)) {
            throw $failed[0];
        }
        foreach ($failed as $e) {
            logger()->warning('[Server Suite] an edge router missed a route; edge-sync will add it', ['fqdn' => $fqdn, 'error' => $e->getMessage()]);
        }
    }

    /** Remove a name's route everywhere. Never throws. True when it's gone from every router. */
    public function remove(string $fqdn): bool
    {
        $all = true;
        foreach ($this->routers as $r) {
            $all = $r->remove($fqdn) && $all;
        }

        return $all;
    }

    /**
     * Each router's routes, keyed by its position. Null for one that can't
     * be asked.
     *
     * @return list<array<string, string>|null>
     */
    public function routesEach(): array
    {
        return array_map(fn (McRouterClient $r) => $r->routes(), $this->routers);
    }
}
