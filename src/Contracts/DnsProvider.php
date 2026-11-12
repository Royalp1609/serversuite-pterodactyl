<?php

namespace ServerSuite\Contracts;

/**
 * A DNS provider that can point a subdomain at a game server: an A record for
 * the IP, plus a SRV record carrying the port for games whose clients look one
 * up (Minecraft: _minecraft._tcp). Bound to one zone and one set of
 * credentials (an admin domain, or a server's own domain).
 */
interface DnsProvider
{
    public function key(): string;

    public function zoneName(): string;

    /**
     * Check the credentials can manage this zone; returns the provider's zone
     * id so it can be stored and reused.
     *
     * @throws \RuntimeException with a message safe to show the user
     */
    public function verify(): string;

    /**
     * Create the A record for $label, and the SRV record for $srvService
     * (null = A record only, #83). Rolls back the A record if the SRV record
     * fails, so a name is never left half-configured.
     *
     * @return array{fqdn: string, a_record_id: string, srv_record_id: ?string}
     *
     * @throws \RuntimeException
     */
    public function createRecords(string $label, string $ip, int $port, ?string $srvService = '_minecraft._tcp'): array;

    /**
     * Point existing records at a new IP / port.
     *
     * @throws \RuntimeException
     */
    public function updateRecords(string $fqdn, ?string $aRecordId, ?string $srvRecordId, string $ip, int $port, string $srvService = '_minecraft._tcp'): void;

    /**
     * What the provider says about the credentials (#139): status is active,
     * expired, disabled, invalid (rejected, or can't see the zone) or unknown
     * (provider unreachable). Never throws.
     *
     * @return array{status: string, expires_on: ?string}
     */
    public function tokenStatus(): array;

    /** Best-effort cleanup; must not throw on records that are already gone. */
    public function deleteRecords(?string $aRecordId, ?string $srvRecordId): void;
}
