<?php

namespace ServerSuite\Dns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DnsProvider;
use ServerSuite\Exceptions\DnsAuthException;

/**
 * Cloudflare DNS for one zone. Ported from the Pelican plugin.
 *
 *  - Use a scoped API token (Zone > DNS > Edit on that zone only), never the
 *    Global API Key. The token is passed in, never logged or returned.
 *  - A records are DNS-only (proxied=false): Cloudflare's proxy doesn't carry
 *    game traffic and would hide the real IP the SRV record points at.
 *  - SRV records only for games whose clients look one up (#83).
 *  - The address record follows the allocation (#132): A for IPv4, AAAA for
 *    IPv6, CNAME for a host name alias. With a host name, the SRV record
 *    targets it directly (an SRV target must not be an alias).
 *  - A name that already has records, made by anyone, is never claimed
 *    (#117), and a delete that didn't happen is reported, not ignored.
 *  - Fixed API base, bearer token, timeouts; labels are validated here as
 *    well as by the caller before going into a record name.
 */
class CloudflareDnsProvider implements DnsProvider
{
    private const API = 'https://api.cloudflare.com/client/v4';

    private const COMMENT = 'Server Suite (Pterodactyl) managed';

    public function __construct(
        private string $token,
        private string $zone,
        private ?string $zoneId = null,
    ) {
        $this->zone = strtolower(trim($zone));
    }

    public function key(): string
    {
        return 'cloudflare';
    }

    public function zoneName(): string
    {
        return $this->zone;
    }

    public function verify(): string
    {
        $res = $this->http()->get(self::API . '/zones', ['name' => $this->zone, 'per_page' => 1]);
        $id = $res->json('result.0.id');

        if (! $res->successful() || ! $id) {
            $msg = "Cloudflare couldn't find the zone {$this->zone} with this token. "
                . 'Check the zone name and that the token has Zone > DNS > Edit for it.';
            throw self::isAuthFailure($res) ? new DnsAuthException($msg) : new \RuntimeException($msg);
        }

        return $this->zoneId = (string) $id;
    }

    public function existingRecords(string $label, ?string $srvService = '_minecraft._tcp'): array
    {
        self::assertLabel($label);
        $fqdn = $label . '.' . $this->zone;
        $base = $this->recordsUrl();

        $types = [];
        foreach (array_filter([$fqdn, $srvService !== null ? self::srvName($fqdn, $srvService) : null]) as $name) {
            $res = $this->http()->get($base, ['name' => $name, 'per_page' => 50]);
            $rows = $res->json('result');
            if (! $res->successful() || ! $res->json('success') || ! is_array($rows) || ! array_is_list($rows)) {
                throw self::failure("Couldn't check {$name} at Cloudflare", $res);
            }
            foreach ($rows as $row) {
                $types[] = (string) ($row['type'] ?? '?');
            }
        }

        return array_values(array_unique($types));
    }

    public function createRecords(string $label, string $target, int $port, ?string $srvService = '_minecraft._tcp'): array
    {
        self::assertLabel($label);
        $fqdn = $label . '.' . $this->zone;
        $address = self::addressRecord($fqdn, $target);
        $base = $this->recordsUrl();

        $a = $this->http()->post($base, $address);
        if (! $a->successful() || ! $a->json('success')) {
            throw self::failure("Cloudflare {$address['type']} record failed", $a);
        }
        $aId = (string) $a->json('result.id');

        if ($srvService === null) {
            return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => null];
        }

        $srv = $this->http()->post($base, $this->srvRecord($fqdn, $port, $srvService, self::srvTarget($fqdn, $target)));
        if (! $srv->successful() || ! $srv->json('success')) {
            // Roll back rather than leave a half-configured name.
            $this->deleteRecords($aId, null);
            throw self::failure('Cloudflare SRV record failed', $srv);
        }

        return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => (string) $srv->json('result.id')];
    }

    public function updateRecords(string $fqdn, ?string $aRecordId, ?string $srvRecordId, string $target, int $port, string $srvService = '_minecraft._tcp'): array
    {
        $address = self::addressRecord($fqdn, $target);
        $base = $this->recordsUrl();

        if ($aRecordId) {
            $cur = $this->http()->get("{$base}/{$aRecordId}");
            if ($cur->status() === 404) {
                // Removed by hand, or a type change that stopped half way: make it again.
                $aRecordId = $this->createAddress($base, $address);
            } elseif (! $cur->successful() || ! $cur->json('success')) {
                throw self::failure('Cloudflare record lookup failed', $cur);
            } elseif (strtoupper((string) $cur->json('result.type')) === $address['type']) {
                $res = $this->http()->patch("{$base}/{$aRecordId}", $address);
                if (! $res->successful() || ! $res->json('success')) {
                    throw self::failure("Cloudflare {$address['type']} record update failed", $res);
                }
            } else {
                // A, AAAA and CNAME can't share a name, so a type change is a
                // delete and a create. If the create fails, the next move
                // finds the old id gone and creates it.
                if (! $this->deleteRecord($base, $aRecordId)) {
                    throw new \RuntimeException('Cloudflare record update failed: the old ' . $cur->json('result.type') . ' record could not be removed.');
                }
                $aRecordId = $this->createAddress($base, $address);
            }
        }

        if ($srvRecordId) {
            $res = $this->http()->patch("{$base}/{$srvRecordId}", $this->srvRecord($fqdn, $port, $srvService, self::srvTarget($fqdn, $target)));
            if (! $res->successful() || ! $res->json('success')) {
                throw self::failure('Cloudflare SRV record update failed', $res);
            }
        }

        return ['a_record_id' => $aRecordId, 'srv_record_id' => $srvRecordId];
    }

    /** @param array<string, mixed> $address */
    private function createAddress(string $base, array $address): string
    {
        $res = $this->http()->post($base, $address);
        if (! $res->successful() || ! $res->json('success')) {
            throw self::failure("Cloudflare {$address['type']} record failed", $res);
        }

        return (string) $res->json('result.id');
    }

    public function tokenStatus(): array
    {
        try {
            $v = $this->http()->get(self::API . '/user/tokens/verify');
            $status = $v->successful() && $v->json('success') && is_string($v->json('result.status')) ? (string) $v->json('result.status') : null;
            $expires = $status !== null ? ($v->json('result.expires_on') ?: null) : null;
            if ($status !== null && $status !== 'active') {
                return ['status' => $status, 'expires_on' => $expires];
            }

            // Also read the zone: an active token can still lack access to
            // it, and account-owned tokens can't use /user/tokens/verify.
            $z = $this->http()->get(self::API . '/zones', ['name' => $this->zone, 'per_page' => 1]);
        } catch (\Throwable) {
            return ['status' => 'unknown', 'expires_on' => null];
        }

        if ($z->successful() && $z->json('result.0.id')) {
            return ['status' => 'active', 'expires_on' => $expires];
        }
        if ($z->successful() || self::isAuthFailure($z) || self::isAuthFailure($v)) {
            return ['status' => 'invalid', 'expires_on' => null];
        }

        return ['status' => 'unknown', 'expires_on' => null];
    }

    public function deleteRecords(?string $aRecordId, ?string $srvRecordId): bool
    {
        $ids = array_filter([$aRecordId, $srvRecordId]);
        if ($ids === []) {
            return true;
        }
        try {
            $base = $this->recordsUrl();
        } catch (\Throwable) {
            return false;
        }

        $ok = true;
        foreach ($ids as $id) {
            $ok = $this->deleteRecord($base, $id) && $ok;
        }

        return $ok;
    }

    /** True when the record is gone: deleted now, or already (404). */
    private function deleteRecord(string $base, string $id): bool
    {
        try {
            $res = $this->http()->delete("{$base}/{$id}");
        } catch (\Throwable) {
            return false;
        }

        return ($res->successful() && $res->json('success') !== false) || $res->status() === 404;
    }

    /**
     * The record for the name itself (#132): A for IPv4, AAAA for IPv6,
     * CNAME for a host name.
     *
     * @return array<string, mixed>
     */
    public static function addressRecord(string $fqdn, string $target): array
    {
        $target = strtolower(rtrim(trim($target), '.'));
        $type = match (true) {
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'A',
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'AAAA',
            self::isHostname($target) && $target !== strtolower($fqdn) => 'CNAME',
            default => throw new \InvalidArgumentException("The server's address \"{$target}\" is not an IP address or a host name, so no DNS record can point at it."),
        };

        return [
            'type' => $type, 'name' => $fqdn, 'content' => $target,
            'ttl' => 300, 'proxied' => false, 'comment' => self::COMMENT,
        ];
    }

    /** Where the SRV record points: the host name alias itself, or the claimed name. */
    private static function srvTarget(string $fqdn, string $target): string
    {
        $target = strtolower(rtrim(trim($target), '.'));

        return filter_var($target, FILTER_VALIDATE_IP) === false && self::isHostname($target) ? $target : $fqdn;
    }

    private static function isHostname(string $name): bool
    {
        return strlen($name) <= 253 && str_contains($name, '.')
            && preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]([a-z0-9-]{0,61}[a-z0-9])?$/', $name) === 1;
    }

    private static function srvName(string $fqdn, string $service): string
    {
        if (! preg_match('/^_[a-z0-9-]+\._(tcp|udp)$/', $service)) {
            throw new \InvalidArgumentException('Invalid SRV service.');
        }

        return $service . '.' . $fqdn;
    }

    public static function assertLabel(string $label): void
    {
        if (! preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
            throw new \InvalidArgumentException('Invalid subdomain label.');
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->token)->acceptJson()->timeout(10)->connectTimeout(5);
    }

    protected function recordsUrl(): string
    {
        return self::API . '/zones/' . ($this->zoneId ?: $this->verify()) . '/dns_records';
    }

    /** @return array<string, mixed> */
    private function srvRecord(string $fqdn, int $port, string $service, ?string $target = null): array
    {
        return [
            'type' => 'SRV', 'name' => self::srvName($fqdn, $service),
            'data' => ['priority' => 0, 'weight' => 5, 'port' => $port, 'target' => $target ?? $fqdn],
            'ttl' => 300, 'comment' => self::COMMENT,
        ];
    }

    private static function failure(string $what, \Illuminate\Http\Client\Response $res): \RuntimeException
    {
        $msg = $what . ': ' . self::error($res);

        return self::isAuthFailure($res) ? new DnsAuthException($msg) : new \RuntimeException($msg);
    }

    /** 401/403, or Cloudflare's codes for a bad, expired or unauthorised token. */
    private static function isAuthFailure(\Illuminate\Http\Client\Response $res): bool
    {
        return in_array($res->status(), [401, 403], true)
            || in_array((int) $res->json('errors.0.code'), [1000, 6003, 6111, 9106, 9109, 10000], true);
    }

    private static function error(\Illuminate\Http\Client\Response $res): string
    {
        return (string) ($res->json('errors.0.message') ?? ('HTTP ' . $res->status()));
    }
}
