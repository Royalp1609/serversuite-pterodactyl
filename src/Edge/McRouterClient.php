<?php

namespace ServerSuite\Edge;

use Illuminate\Support\Facades\Http;

/**
 * The routes API of mc-router (https://github.com/itzg/mc-router), checked
 * against 1.56.5 (#2). POST /routes adds or replaces a route, DELETE
 * /routes/<name> removes one (404 when it isn't there), GET /routes lists
 * them as {name: {backend: "host:port", ...}}. Names are lowercased by
 * mc-router.
 *
 * The API has no authentication: its URL must be reachable only from the
 * panel (a private address or a firewalled port), never from the internet.
 */
class McRouterClient
{
    public function __construct(private string $baseUrl, private int $timeout = 5) {}

    /**
     * Point a name at a backend (create or replace).
     *
     * @throws \RuntimeException with a message safe to show the admin
     */
    public function put(string $fqdn, string $backend): void
    {
        try {
            $res = $this->http()->post($this->url('/routes'), ['serverAddress' => strtolower($fqdn), 'backend' => $backend]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("The edge router can't be reached ({$this->host()}).", previous: $e);
        }
        if (! $res->successful()) {
            throw new \RuntimeException("The edge router refused the route for {$fqdn} (HTTP {$res->status()}).");
        }
    }

    /** Remove a name's route. Never throws. True when it's gone (removed now or already absent). */
    public function remove(string $fqdn): bool
    {
        try {
            $res = $this->http()->delete($this->url('/routes/' . rawurlencode(strtolower($fqdn))));

            return $res->successful() || $res->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every route: name => backend. Null when the router can't be asked.
     *
     * @return array<string, string>|null
     */
    public function routes(): ?array
    {
        try {
            $res = $this->http()->get($this->url('/routes'));
        } catch (\Throwable) {
            return null;
        }
        if (! $res->successful() || ! is_array($res->json())) {
            return null;
        }
        $out = [];
        foreach ($res->json() as $name => $route) {
            $backend = is_array($route) ? ($route['backend'] ?? null) : $route;
            if (is_string($name) && is_string($backend)) {
                $out[strtolower($name)] = $backend;
            }
        }

        return $out;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($this->timeout)->connectTimeout(min(3, $this->timeout))->acceptJson()->asJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    private function host(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl);
    }
}
