<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\DB;
use ServerSuite\Contracts\DnsProvider;
use ServerSuite\Edge\EdgeMode;
use ServerSuite\Dns\CloudflareDnsProvider;
use ServerSuite\Exceptions\DnsAuthException;
use ServerSuite\Exceptions\SubdomainException;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;
use ServerSuite\Models\SubdomainDomain;

/**
 * Subdomains for game servers (#14): claim `<label>.<domain>` on a domain the
 * panel admin offers or on the server's own Cloudflare domain, and point it at
 * the server's primary allocation (A + SRV records).
 *
 * Rules:
 *  - Each server has an admin-set allowance (default 0) covering every
 *    subdomain it holds, on any domain. "Buying" one happens outside the
 *    panel: the admin takes payment, then raises the allowance.
 *  - A label must be valid, not reserved, and unique per zone.
 *  - Tokens (admin domains, own domain) are encrypted at rest and never
 *    returned by overview().
 */
class SubdomainService
{
    /** Warn this many days before an admin domain's token expires (#139). */
    public const TOKEN_WARN_DAYS = 14;

    /** @var (callable(string, string, ?string): DnsProvider)|null */
    private $factory;

    /** @var (callable(object): ?\DateTimeInterface)|null */
    private $startedAt;

    /**
     * @param (callable(string $token, string $zone, ?string $zoneId): DnsProvider)|null $factory
     * @param (callable(object $server): ?\DateTimeInterface)|null $startedAt when the running server started (null: not running / unknown)
     */
    public function __construct(?callable $factory = null, ?callable $startedAt = null)
    {
        $this->factory = $factory;
        $this->startedAt = $startedAt;
    }

    // -------------------------------------------------------------------------
    // Server owner
    // -------------------------------------------------------------------------

    /**
     * Everything the Subdomains screen needs. No tokens.
     *
     * @return array<string, mixed>
     */
    public function overview(object $server): array
    {
        $game = $this->game($server);
        $setting = ModManagerSetting::forServer($server->uuid);
        [$ip, $port] = $this->target($server);
        $items = Subdomain::query()->where('server_uuid', $server->uuid)->orderBy('fqdn')->get();

        return [
            'allowance' => (int) $setting->subdomain_limit,
            'used'      => $items->count(),
            'target'    => ['ip' => $ip, 'port' => $port],
            'domains'   => SubdomainDomain::query()->where('enabled', true)->orderBy('zone')->get()
                ->map(fn (SubdomainDomain $d) => ['id' => $d->id, 'zone' => $d->zone])->values()->all(),
            'own'       => [
                'zone'       => $setting->own_dns_zone,
                'configured' => $setting->own_dns_zone !== null && $setting->own_dns_token !== null,
            ],
            'items'     => $items->map(fn (Subdomain $s) => [
                'id'    => $s->id,
                'fqdn'  => $s->fqdn,
                'own'   => $s->domain_id === null,
                'ip'    => $s->ip,
                'port'  => $s->port,
                'stale' => $ip !== null && ($s->ip !== $ip || $s->port !== $port),
                'move_pending' => $s->move_pending_since !== null,
                'address' => $s->srv_record_id !== null || $s->edge_backend !== null ? $s->fqdn : $s->fqdn . ':' . $s->port,
                'edge'    => $s->edge_backend !== null,
                'status_public' => (bool) $s->status_public,
                'card_url'  => $s->status_public && StatusCardService::enabled() ? StatusCardService::url($s->fqdn) : null,
                'badge_url' => $s->status_public && StatusCardService::enabled() ? StatusCardService::url($s->fqdn, true) : null,
            ])->values()->all(),
            // Status cards ping with the Minecraft protocol; games without a
            // SRV lookup join with name:port (#79).
            'status_cards' => StatusCardService::enabled() && $game->isMinecraft(),
            'game'      => ['name' => $game->name, 'srv' => $game->srvService !== null],
            'reserved'  => $this->reserved(),
        ];
    }

    /**
     * @param int|string $domain an admin domain id, or 'own'
     *
     * @throws SubdomainException
     */
    public function claim(object $server, string $label, int|string $domain, ?int $userId = null): Subdomain
    {
        // One claim at a time (#141/#139 item 58): the allowance and "already
        // taken" checks then can't both pass for two requests at once.
        // Claims are rare, so one panel-wide lock is enough.
        try {
            return \Illuminate\Support\Facades\Cache::lock('serversuite:subdomain-claim', 60)
                ->block(20, fn () => $this->claimLocked($server, $label, $domain, $userId));
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw new SubdomainException('Another subdomain is being set up right now. Try again in a moment.');
        }
    }

    /** @throws SubdomainException */
    private function claimLocked(object $server, string $label, int|string $domain, ?int $userId): Subdomain
    {
        $label = strtolower(trim($label));
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            throw new SubdomainException('Use 1-63 characters: a-z, 0-9 and hyphens (not at the start or end).');
        }
        if (in_array($label, $this->reserved(), true)) {
            throw new SubdomainException("\"{$label}\" is reserved and can't be claimed.");
        }

        $setting = ModManagerSetting::forServer($server->uuid);
        $allowance = (int) $setting->subdomain_limit;
        if ($allowance < 1) {
            throw new SubdomainException('This server has no subdomain allowance. Ask a panel admin to add one.');
        }
        if (Subdomain::query()->where('server_uuid', $server->uuid)->count() >= $allowance) {
            throw new SubdomainException("This server's allowance is {$allowance}. Remove a subdomain first, or ask a panel admin for more.");
        }

        if ($domain === 'own') {
            if ($setting->own_dns_zone === null || $setting->own_dns_token === null) {
                throw new SubdomainException('Set up your own domain first.');
            }
            $domainId = null;
            $d = null;
            $dns = $this->provider($setting->own_dns_token, $setting->own_dns_zone, $setting->own_dns_zone_id);
        } else {
            $d = SubdomainDomain::query()->where('enabled', true)->find((int) $domain);
            if ($d === null) {
                throw new SubdomainException('That domain is not available.');
            }
            $domainId = $d->id;
            $dns = $this->provider($d->token, $d->zone, $d->zone_id);
        }

        if ($dns instanceof CloudflareDnsProvider) {
            $dns->forServer($server->uuid);
        }
        $zone = $dns->zoneName();
        if (Subdomain::query()->where('fqdn', "{$label}.{$zone}")->exists()) {
            throw new SubdomainException("{$label}.{$zone} is already taken.");
        }

        [$ip, $port] = $this->target($server);
        if ($ip === null || $port === null) {
            throw new SubdomainException('This server has no primary allocation to point the subdomain at.');
        }

        $srvService = $this->game($server)->srvService;

        // Edge mode (#2): Java Minecraft joins through the hostname router.
        $edge = EdgeMode::appliesTo($server, $this->game($server)) ? EdgeMode::fromConfig() : null;
        $backend = $edge !== null ? EdgeMode::backend($server) : null;
        if ($edge !== null && $backend === null) {
            throw new SubdomainException('This server has no internal address for the edge router to send players to.');
        }

        try {
            // #117: the zone may already use the name (the admin's shop, wiki,
            // or records an earlier release couldn't remove). Never add to it.
            $existing = $dns->existingRecords($label, $srvService);
        } catch (\Throwable $e) {
            throw $this->dnsFailure($e, $d);
        }
        if ($existing !== []) {
            throw new SubdomainException("{$label}.{$zone} already has DNS records (" . implode(', ', $existing) . ') in the zone, so it can\'t be claimed. Pick another name.');
        }

        try {
            // On the edge: the address record points at the edge, players
            // join on its port 25565, so no SRV record.
            $res = $edge !== null
                ? $dns->createRecords($label, $edge->address, EdgeMode::PORT, null)
                : $dns->createRecords($label, $ip, $port, $srvService);
        } catch (\Throwable $e) {
            throw $this->dnsFailure($e, $d);
        }

        if ($edge !== null) {
            try {
                $edge->router->put($res['fqdn'], $backend);
            } catch (\RuntimeException $e) {
                $dns->deleteRecords($res['a_record_id'], $res['srv_record_id']);
                logger()->warning('[Server Suite] edge route failed', ['fqdn' => $res['fqdn'], 'error' => $e->getMessage()]);
                throw new SubdomainException('The subdomain could not be set up on the edge router. Try again in a few minutes, or ask a panel admin to check it.', previous: $e);
            }
        }

        try {
            return $this->saveClaim($server, $dns, $domainId, $label, $zone, $res, $ip, $port, $userId, $backend);
        } catch (\Throwable $e) {
            // The records are made but nothing in the panel knows them: remove them.
            $dns->deleteRecords($res['a_record_id'], $res['srv_record_id']);
            $edge?->router->remove($res['fqdn']);
            throw $e;
        }
    }

    /** @param array{fqdn: string, a_record_id: string, srv_record_id: ?string} $res */
    private function saveClaim(object $server, DnsProvider $dns, ?int $domainId, string $label, string $zone, array $res, string $ip, int $port, ?int $userId, ?string $edgeBackend = null): Subdomain
    {
        return Subdomain::query()->create([
            'server_uuid'   => $server->uuid,
            'domain_id'     => $domainId,
            'provider'      => $dns->key(),
            'label'         => $label,
            'zone'          => $zone,
            'fqdn'          => $res['fqdn'],
            'a_record_id'   => $res['a_record_id'],
            'srv_record_id' => $res['srv_record_id'],
            'ip'            => $ip,
            'port'          => $port,
            'created_by'    => $userId,
            'edge_backend'  => $edgeBackend,
        ]);
    }

    /**
     * Remove a subdomain. Its row goes only once the records are gone (#117):
     * a row deleted while the records stay leaves the name stuck in DNS with
     * nothing in the panel able to remove it.
     *
     * @throws SubdomainException
     */
    public function release(object $server, int $id): void
    {
        $sub = $this->ownedBy($server, $id);
        $dns = $this->providerFor($sub);
        if ($dns !== null && ! $dns->deleteRecords($sub->a_record_id, $sub->srv_record_id)) {
            throw new SubdomainException("Couldn't remove the DNS records for {$sub->fqdn} at Cloudflare, so the subdomain is kept. Try again in a few minutes. If it keeps failing, the domain's API token may need replacing.");
        }
        $this->removeEdgeRoute($sub);
        $sub->delete();
    }

    /**
     * Point a subdomain at the server's current primary allocation.
     *
     * @throws SubdomainException
     */
    public function repoint(object $server, int $id): Subdomain
    {
        $sub = $this->ownedBy($server, $id);
        [$ip, $port] = $this->target($server);
        if ($ip === null || $port === null) {
            throw new SubdomainException('This server has no primary allocation to point the subdomain at.');
        }

        // On the edge (#2) the DNS record stays on the edge: move the route.
        if ($sub->edge_backend !== null) {
            $router = EdgeMode::router();
            $backend = EdgeMode::backend($server);
            if ($router === null || $backend === null) {
                throw new SubdomainException('The edge router is not configured, so this subdomain can\'t be moved. Ask a panel admin.');
            }
            try {
                $router->put($sub->fqdn, $backend);
            } catch (\RuntimeException $e) {
                logger()->warning('[Server Suite] edge route move failed', ['fqdn' => $sub->fqdn, 'error' => $e->getMessage()]);
                throw new SubdomainException('The edge router could not be updated. Try again in a few minutes.', previous: $e);
            }
            $sub->forceFill(['ip' => $ip, 'port' => $port, 'move_pending_since' => null, 'edge_backend' => $backend])->save();

            return $sub;
        }

        $dns = $this->providerFor($sub);
        if ($dns === null) {
            throw new SubdomainException('The domain this subdomain is on is no longer configured.');
        }

        try {
            // A name with a SRV record keeps it; its service is the game's
            // (Minecraft for every record made before #79).
            $ids = $dns->updateRecords($sub->fqdn, $sub->a_record_id, $sub->srv_record_id, $ip, $port, $this->game($server)->srvService ?? '_minecraft._tcp');
        } catch (\Throwable $e) {
            throw $this->dnsFailure($e, $sub->domain_id !== null ? SubdomainDomain::query()->find($sub->domain_id) : null);
        }

        // A change of record type (#132) gives the address record a new id.
        $sub->forceFill(['ip' => $ip, 'port' => $port, 'move_pending_since' => null, 'a_record_id' => $ids['a_record_id'] ?? $sub->a_record_id])->save();

        return $sub;
    }

    /**
     * A server's primary allocation changed (#37): its subdomains wait for
     * the server to start on it. Called from the Server::updated hook.
     */
    public function markMovePending(string $serverUuid): void
    {
        Subdomain::query()->where('server_uuid', $serverUuid)->update(['move_pending_since' => now()]);
    }

    /**
     * Move subdomains whose server has a new primary allocation, once the
     * server has started since the change. A new port only takes effect when
     * the server (re)starts, and "something answers on the port" doesn't
     * prove it: Docker answers on every mapped port, including a secondary
     * allocation the game isn't on. Run every minute by the scheduler; also
     * retries moves that failed at Cloudflare. Returns how many moved.
     */
    public function applyPendingMoves(bool $full = true): int
    {
        $model = '\\Pterodactyl\\Models\\Server';
        if (! class_exists($model)) {
            return 0;
        }

        $moved = 0;
        // $full = false: only servers with a move already waiting.
        $scope = Subdomain::query()->when(! $full, fn ($q) => $q->whereNotNull('move_pending_since'));
        foreach ($scope->distinct()->pluck('server_uuid')->chunk(200) as $uuids) {
            $subs = Subdomain::query()->whereIn('server_uuid', $uuids)->get()->groupBy('server_uuid');
            $servers = $model::query()->with('allocation')->whereIn('uuid', $uuids)->get();
            foreach ($servers as $server) {
                $all = $subs[$server->uuid] ?? collect();
                [$ip, $port] = $this->target($server);
                $waiting = $all->filter(fn (Subdomain $s) => $ip !== null && ($s->ip !== $ip || $s->port !== $port));

                // Switched back before a restart: nothing to move any more.
                $settled = $all->diff($waiting)->filter(fn (Subdomain $s) => $s->move_pending_since !== null);
                if ($settled->isNotEmpty()) {
                    Subdomain::query()->whereIn('id', $settled->pluck('id'))->update(['move_pending_since' => null]);
                }
                if ($waiting->isEmpty()) {
                    continue;
                }

                // Changed without the hook (plugin off, older version): wait
                // for a start from now on.
                $unmarked = $waiting->filter(fn (Subdomain $s) => $s->move_pending_since === null);
                if ($unmarked->isNotEmpty()) {
                    Subdomain::query()->whereIn('id', $unmarked->pluck('id'))->update(['move_pending_since' => now()]);
                    continue;
                }

                $since = $waiting->max('move_pending_since');
                $started = $this->startedAt($server);
                if ($started === null || $started < $since) {
                    continue;
                }

                foreach ($waiting as $sub) {
                    try {
                        $this->repoint($server, $sub->id);
                        $moved++;
                    } catch (\Throwable $e) {
                        logger()->warning('[Server Suite] subdomain move failed', ['fqdn' => $sub->fqdn, 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        return $moved;
    }

    /** When the server started, if Wings says it's running; null otherwise or when unreachable. */
    private function startedAt(object $server): ?\DateTimeInterface
    {
        if ($this->startedAt !== null) {
            return ($this->startedAt)($server);
        }

        $repo = '\\Pterodactyl\\Repositories\\Wings\\DaemonServerRepository';
        try {
            $d = app($repo)->setServer($server)->getDetails();
        } catch (\Throwable) {
            return null;
        }
        $state = $d['state'] ?? ($d['utilization']['state'] ?? null);
        $uptime = (int) ($d['utilization']['uptime'] ?? 0);

        return $state === 'running' && $uptime > 0 ? now()->subMilliseconds($uptime) : null;
    }

    /**
     * Remove a deleted server's records and rows, then forget its own-domain
     * token so no credential outlives the server (#35). A subdomain whose
     * records couldn't be removed keeps its row (and the own-domain token
     * when it's on that domain), so serversuite:retry-subdomain-cleanup can
     * try again later instead of leaving the names stuck in DNS (#117).
     */
    public function cleanupServer(string $serverUuid): void
    {
        $ownLeft = false;
        foreach (Subdomain::query()->where('server_uuid', $serverUuid)->get() as $sub) {
            $dns = $this->providerFor($sub);
            if ($dns !== null && ! $dns->deleteRecords($sub->a_record_id, $sub->srv_record_id)) {
                $ownLeft = $ownLeft || $sub->domain_id === null;
                logger()->warning('[Server Suite] DNS records of a deleted server could not be removed; kept for serversuite:retry-subdomain-cleanup', ['fqdn' => $sub->fqdn]);

                continue;
            }
            $this->removeEdgeRoute($sub);
            $sub->delete();
        }

        ModManagerSetting::query()->where('server_uuid', $serverUuid)
            ->update($ownLeft ? ['subdomain_limit' => 0] : ['own_dns_zone' => null, 'own_dns_zone_id' => null, 'own_dns_token' => null, 'subdomain_limit' => 0]);
    }

    /**
     * Subdomains of servers that no longer exist (deleted while the plugin
     * was off, or whose records couldn't be removed then, #117): clean each
     * as if it had just been deleted. With $dryRun only the count is returned.
     */
    public function cleanupOrphans(bool $dryRun = false): int
    {
        $orphans = Subdomain::query()->whereNotIn('server_uuid', DB::table('servers')->select('uuid'));
        $count = (clone $orphans)->count();
        if (! $dryRun) {
            foreach ((clone $orphans)->distinct()->pluck('server_uuid') as $uuid) {
                $this->cleanupServer((string) $uuid);
            }
        }

        return $count;
    }

    /**
     * Save the server's own Cloudflare domain. A blank token keeps the stored
     * one. The token is checked against the zone before anything is saved.
     *
     * @throws SubdomainException
     */
    public function saveOwnDomain(object $server, string $zone, ?string $token): void
    {
        $zone = self::normaliseZone($zone);
        $setting = ModManagerSetting::forServer($server->uuid);

        if ($setting->own_dns_zone !== null && $setting->own_dns_zone !== $zone && $this->ownSubdomainsExist($server)) {
            throw new SubdomainException('Remove the subdomains on your current domain before changing it.');
        }

        $token = ($token !== null && trim($token) !== '') ? trim($token) : $setting->own_dns_token;
        if ($token === null) {
            throw new SubdomainException('Enter a Cloudflare API token for the domain.');
        }

        // Same zone with subdomains on it: a new token must see the zone their
        // records are in, as for an admin domain (#140).
        $sameZoneInUse = $setting->own_dns_zone === $zone && $this->ownSubdomainsExist($server);
        [$token, $zoneId] = $this->replacementToken($token, $zone, $setting->own_dns_zone_id, $sameZoneInUse);
        $setting->forceFill(['own_dns_zone' => $zone, 'own_dns_zone_id' => $zoneId, 'own_dns_token' => $token])->save();
    }

    /** @throws SubdomainException */
    public function removeOwnDomain(object $server): void
    {
        if ($this->ownSubdomainsExist($server)) {
            throw new SubdomainException('Remove the subdomains on your own domain first.');
        }

        ModManagerSetting::forServer($server->uuid)
            ->forceFill(['own_dns_zone' => null, 'own_dns_zone_id' => null, 'own_dns_token' => null])->save();
    }

    // -------------------------------------------------------------------------
    // Panel admin
    // -------------------------------------------------------------------------

    /** @throws SubdomainException */
    public function addDomain(string $zone, string $token): SubdomainDomain
    {
        $zone = self::normaliseZone($zone);
        $token = trim($token);
        if ($token === '') {
            throw new SubdomainException('Enter a Cloudflare API token for the domain.');
        }
        if (SubdomainDomain::query()->where('zone', $zone)->exists()) {
            throw new SubdomainException("{$zone} is already listed.");
        }

        $d = SubdomainDomain::query()->create([
            'zone' => $zone, 'zone_id' => $this->verified($token, $zone), 'token' => $token, 'enabled' => true,
        ]);

        return $this->checkDomainToken($d);
    }

    /**
     * Swap an admin domain's token without touching the subdomains on it
     * (#140). Cloudflare tokens can expire, and a domain with subdomains
     * can't be removed and added again.
     *
     * @throws SubdomainException
     */
    public function replaceDomainToken(int $id, string $token): SubdomainDomain
    {
        $d = SubdomainDomain::query()->find($id) ?? throw new SubdomainException('Domain not found.');
        $inUse = Subdomain::query()->where('domain_id', $d->id)->exists();

        [$token, $zoneId] = $this->replacementToken($token, $d->zone, $d->zone_id, $inUse);
        $d->forceFill(['token' => $token, 'zone_id' => $zoneId, 'token_status' => null, 'token_expires_at' => null, 'token_warned' => null])->save();

        return $this->checkDomainToken($d);
    }

    /**
     * Check every admin domain's token with Cloudflare and warn the admin
     * once per problem (#139). Run daily; returns the domains that need a
     * new token.
     *
     * @return list<SubdomainDomain>
     */
    public function checkDomainTokens(): array
    {
        $out = [];
        foreach (SubdomainDomain::query()->orderBy('zone')->get() as $d) {
            if (self::tokenWarning($this->checkDomainToken($d)) !== null) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * Store what Cloudflare says about one admin domain's token, and notify
     * the admin's default targets when a new warning appears. Never throws;
     * an unreachable Cloudflare leaves the stored state as it was.
     */
    public function checkDomainToken(SubdomainDomain $d): SubdomainDomain
    {
        try {
            $st = $this->provider($d->token, $d->zone, $d->zone_id)->tokenStatus();
            if ($st['status'] === 'unknown') {
                return $d;
            }

            $d->forceFill([
                'token_status'     => $st['status'],
                'token_expires_at' => $st['expires_on'] !== null ? \Illuminate\Support\Carbon::parse($st['expires_on']) : null,
                'token_checked_at' => now(),
            ]);
            $warn = self::tokenWarning($d);
            if ($warn !== null && $warn['key'] !== $d->token_warned) {
                app(Notifier::class)->notifyAdmin($warn['title'], $warn['body'], $warn['level'], "dns-token:{$d->zone}:{$warn['key']}");
            }
            $d->forceFill(['token_warned' => $warn['key'] ?? null])->save();
        } catch (\Throwable $e) {
            logger()->warning('[Server Suite] DNS token check failed', ['zone' => $d->zone, 'error' => $e->getMessage()]);
        }

        return $d;
    }

    /**
     * What's wrong with an admin domain's token, from the last check: null
     * when nothing is. `key` changes with each new problem (a new expiry date
     * is a new warning).
     *
     * @return array{key: string, level: string, title: string, short: string, body: string}|null
     */
    public static function tokenWarning(SubdomainDomain $d): ?array
    {
        $fix = 'Create a new Cloudflare API token (Zone > DNS > Edit for this domain) and use "Replace token" under Subdomains on the Server Suite admin page.';
        $expires = $d->token_expires_at;
        $status = $d->token_status;
        if ($status === 'active' && $expires !== null && $expires->getTimestamp() <= time()) {
            $status = 'expired';
        }

        $broken = ['expired' => 'has expired', 'disabled' => 'is disabled in Cloudflare', 'invalid' => 'was rejected by Cloudflare'];
        if (isset($broken[$status])) {
            return [
                'key'   => $status,
                'level' => 'error',
                'title' => "The Cloudflare token for {$d->zone} {$broken[$status]}",
                'short' => 'Token ' . $broken[$status],
                'body'  => "New subdomains and moves on {$d->zone} fail until the token is replaced. Existing records keep resolving. {$fix}",
            ];
        }

        if ($status === 'active' && $expires !== null) {
            $days = intdiv($expires->getTimestamp() - time(), 86400);
            if ($days < self::TOKEN_WARN_DAYS) {
                $when = $days < 1 ? 'today' : ($days === 1 ? 'in 1 day' : "in {$days} days");

                return [
                    'key'   => 'expiring:' . $expires->format('Y-m-d'),
                    'level' => 'warn',
                    'title' => "The Cloudflare token for {$d->zone} expires {$when} ({$expires->format('Y-m-d')})",
                    'short' => "Token expires {$when}",
                    'body'  => "After that, new subdomains and moves on {$d->zone} fail. {$fix}",
                ];
            }
        }

        return null;
    }

    /**
     * A DNS error for the server owner. A rejected token can only be fixed
     * by whoever owns it, so say who (#139) instead of passing on
     * Cloudflare's "Authentication error".
     */
    private function dnsFailure(\Throwable $e, ?SubdomainDomain $d): SubdomainException
    {
        if (! $e instanceof DnsAuthException) {
            return new SubdomainException($e->getMessage(), previous: $e);
        }
        if ($d === null) {
            return new SubdomainException("Cloudflare rejected your own domain's API token (it may have expired). Enter a new token for your domain, then try again.", previous: $e);
        }

        $this->checkDomainToken($d);

        return new SubdomainException("DNS changes on {$d->zone} are failing because Cloudflare rejected the panel's token for it (it may have expired). Ask a panel admin to replace the token.", previous: $e);
    }

    /**
     * A checked replacement token and the zone id it sees. When subdomains
     * exist, the zone must be the one their records are in (a recreated
     * zone with the same name has new ids and none of the records).
     *
     * @return array{0: string, 1: string}
     *
     * @throws SubdomainException
     */
    private function replacementToken(string $token, string $zone, ?string $zoneId, bool $inUse): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new SubdomainException('Enter the new Cloudflare API token.');
        }

        $seen = $this->verified($token, $zone);
        if ($inUse && $zoneId !== null && $seen !== $zoneId) {
            throw new SubdomainException("That token sees a different {$zone} zone from the one the subdomains are in. Use a token for the original zone.");
        }

        return [$token, $seen];
    }

    /** @throws SubdomainException */
    public function removeDomain(int $id): void
    {
        $d = SubdomainDomain::query()->findOrFail($id);
        $count = Subdomain::query()->where('domain_id', $d->id)->count();
        if ($count > 0) {
            throw new SubdomainException("{$d->zone} still has {$count} subdomain(s). Disable it instead, or remove those first.");
        }
        $d->delete();
    }

    public function setDomainEnabled(int $id, bool $enabled): void
    {
        SubdomainDomain::query()->findOrFail($id)->forceFill(['enabled' => $enabled])->save();
    }

    public function setAllowance(string $serverUuid, int $limit): void
    {
        ModManagerSetting::forServer($serverUuid)->forceFill(['subdomain_limit' => max(0, min(100, $limit))])->save();
    }

    // -------------------------------------------------------------------------

    /** @return string[] */
    public function reserved(): array
    {
        return array_values(array_map('strtolower', (array) config('serversuite.subdomains.reserved', [])));
    }

    /**
     * The server's primary allocation: the alias if set (what players use),
     * else the IP.
     *
     * @return array{0: ?string, 1: ?int}
     */
    protected function target(object $server): array
    {
        $alloc = $server->allocation ?? null;
        if (! $alloc) {
            return [null, null];
        }
        $ip = ($alloc->ip_alias ?? null) ?: ($alloc->ip ?? null);

        return [$ip ? (string) $ip : null, isset($alloc->port) ? (int) $alloc->port : null];
    }

    /**
     * Put back edge routes the router lost or that point elsewhere (#2): a
     * restarted router without -routes-config, or a route changed by hand.
     * Never removes routes it didn't make. Returns how many were fixed, or
     * null when the router isn't configured or can't be reached.
     */
    public function syncEdgeRoutes(): ?int
    {
        $router = EdgeMode::router();
        if ($router === null || ($routes = $router->routes()) === null) {
            return null;
        }
        $fixed = 0;
        foreach (Subdomain::query()->whereNotNull('edge_backend')->get() as $sub) {
            if (($routes[strtolower($sub->fqdn)] ?? null) === $sub->edge_backend) {
                continue;
            }
            try {
                $router->put($sub->fqdn, $sub->edge_backend);
                $fixed++;
            } catch (\RuntimeException $e) {
                logger()->warning('[Server Suite] edge route resync failed', ['fqdn' => $sub->fqdn, 'error' => $e->getMessage()]);
            }
        }

        return $fixed;
    }

    /** Best effort: a route left behind only reaches a name whose DNS is gone. */
    private function removeEdgeRoute(Subdomain $sub): void
    {
        if ($sub->edge_backend === null) {
            return;
        }
        $router = EdgeMode::router();
        if ($router === null || ! $router->remove($sub->fqdn)) {
            logger()->warning('[Server Suite] edge route could not be removed', ['fqdn' => $sub->fqdn]);
        }
    }

    /** @throws SubdomainException */
    private function ownedBy(object $server, int $id): Subdomain
    {
        $sub = Subdomain::query()->where('server_uuid', $server->uuid)->find($id);
        if ($sub === null) {
            throw new SubdomainException('Subdomain not found on this server.');
        }

        return $sub;
    }

    private function ownSubdomainsExist(object $server): bool
    {
        return Subdomain::query()->where('server_uuid', $server->uuid)->whereNull('domain_id')->exists();
    }

    /** Credentials for the zone a subdomain lives in, or null if they're gone. */
    private function providerFor(Subdomain $sub): ?DnsProvider
    {
        if ($sub->domain_id !== null) {
            $d = SubdomainDomain::query()->find($sub->domain_id);

            $dns = $d ? $this->provider($d->token, $d->zone, $d->zone_id) : null;

            return $dns instanceof CloudflareDnsProvider ? $dns->forServer($sub->server_uuid) : $dns;
        }

        $setting = ModManagerSetting::forServer($sub->server_uuid);

        $dns = ($setting->own_dns_token !== null && $setting->own_dns_zone === $sub->zone)
            ? $this->provider($setting->own_dns_token, $setting->own_dns_zone, $setting->own_dns_zone_id)
            : null;

        return $dns instanceof CloudflareDnsProvider ? $dns->forServer($sub->server_uuid) : $dns;
    }

    private function provider(string $token, string $zone, ?string $zoneId): DnsProvider
    {
        return $this->factory
            ? ($this->factory)($token, $zone, $zoneId)
            : new CloudflareDnsProvider($token, $zone, $zoneId);
    }

    /** @throws SubdomainException */
    private function verified(string $token, string $zone): string
    {
        try {
            return $this->provider($token, $zone, null)->verify();
        } catch (\Throwable $e) {
            throw new SubdomainException($e->getMessage(), previous: $e);
        }
    }

    /** @throws SubdomainException */
    private static function normaliseZone(string $zone): string
    {
        $zone = strtolower(trim($zone, " \t\n\r\0\x0B."));
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $zone)) {
            throw new SubdomainException('Enter a domain name like example.com.');
        }

        return $zone;
    }

    /** The server's game (#79): which SRV record its subdomains get. */
    private function game(object $server): \ServerSuite\Support\Data\GameProfile
    {
        return app(GameDetectionService::class)->for($server);
    }

}
