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
     * The types of any records already at $label or its SRV name, whoever
     * made them (#117). Empty = free.
     *
     * @return list<string>
     *
     * @throws \RuntimeException when the provider can't be asked
     */
    public function existingRecords(string $label, ?string $srvService = '_minecraft._tcp'): array;

    /**
     * Create the address record for $label (A, AAAA or CNAME, from $target:
     * an IPv4 or IPv6 address, or a host name, #132) and the SRV record for
     * $srvService (null = address record only, #83). Rolls back the address
     * record if the SRV record fails, so a name is never left half-configured.
     * The address record's id is returned as a_record_id whatever its type.
     *
     * @return array{fqdn: string, a_record_id: string, srv_record_id: ?string}
     *
     * @throws \RuntimeException
     */
    public function createRecords(string $label, string $target, int $port, ?string $srvService = '_minecraft._tcp'): array;

    /**
     * Point existing records at a new address / port. A change of record
     * type (say IPv4 to a host name) replaces the address record, so its id
     * can change: store what comes back.
     *
     * @return array{a_record_id: ?string, srv_record_id: ?string}
     *
     * @throws \RuntimeException
     */
    public function updateRecords(string $fqdn, ?string $aRecordId, ?string $srvRecordId, string $target, int $port, string $srvService = '_minecraft._tcp'): array;

    /**
     * What the provider says about the credentials (#139): status is active,
     * expired, disabled, invalid (rejected, or can't see the zone) or unknown
     * (provider unreachable). Never throws.
     *
     * @return array{status: string, expires_on: ?string}
     */
    public function tokenStatus(): array;

    /**
     * Remove the records. Never throws. True when they are all gone (deleted
     * now, or already gone); false when any is still there or the provider
     * couldn't be reached (#117), so the caller can keep its row and retry.
     */
    public function deleteRecords(?string $aRecordId, ?string $srvRecordId): bool;
}
