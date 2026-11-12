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

    public function createRecords(string $label, string $ip, int $port, ?string $srvService = '_minecraft._tcp'): array
    {
        self::assertLabel($label);
        $fqdn = $label . '.' . $this->zone;
        $base = $this->recordsUrl();

        $a = $this->http()->post($base, $this->aRecord($fqdn, $ip));
        if (! $a->successful() || ! $a->json('success')) {
            throw self::failure('Cloudflare A record failed', $a);
        }
        $aId = (string) $a->json('result.id');

        if ($srvService === null) {
            return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => null];
        }

        $srv = $this->http()->post($base, $this->srvRecord($fqdn, $port, $srvService));
        if (! $srv->successful() || ! $srv->json('success')) {
            // Roll back rather than leave a half-configured name.
            $this->deleteRecords($aId, null);
            throw self::failure('Cloudflare SRV record failed', $srv);
        }

        return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => (string) $srv->json('result.id')];
    }

    public function updateRecords(string $fqdn, ?string $aRecordId, ?string $srvRecordId, string $ip, int $port, string $srvService = '_minecraft._tcp'): void
    {
        $base = $this->recordsUrl();

        if ($aRecordId) {
            $res = $this->http()->patch("{$base}/{$aRecordId}", $this->aRecord($fqdn, $ip));
            if (! $res->successful() || ! $res->json('success')) {
                throw self::failure('Cloudflare A record update failed', $res);
            }
        }

        if ($srvRecordId) {
            $res = $this->http()->patch("{$base}/{$srvRecordId}", $this->srvRecord($fqdn, $port, $srvService));
            if (! $res->successful() || ! $res->json('success')) {
                throw self::failure('Cloudflare SRV record update failed', $res);
            }
        }
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

    public function deleteRecords(?string $aRecordId, ?string $srvRecordId): void
    {
        try {
            $base = $this->recordsUrl();
        } catch (\Throwable) {
            return;
        }

        foreach ([$aRecordId, $srvRecordId] as $id) {
            if ($id) {
                try {
                    $this->http()->delete("{$base}/{$id}");
                } catch (\Throwable) {
                    // best effort: a record removed by hand is already gone
                }
            }
        }
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
    private function aRecord(string $fqdn, string $ip): array
    {
        return [
            'type' => 'A', 'name' => $fqdn, 'content' => $ip,
            'ttl' => 300, 'proxied' => false, 'comment' => self::COMMENT,
        ];
    }

    /** @return array<string, mixed> */
    private function srvRecord(string $fqdn, int $port, string $service): array
    {
        if (! preg_match('/^_[a-z0-9-]+\._(tcp|udp)$/', $service)) {
            throw new \InvalidArgumentException('Invalid SRV service.');
        }

        return [
            'type' => 'SRV', 'name' => $service . '.' . $fqdn,
            'data' => ['priority' => 0, 'weight' => 5, 'port' => $port, 'target' => $fqdn],
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
            || in_array((int) $res->json('errors.0.code'), [1000, 6003, 6111, 9109, 10000], true);
    }

    private static function error(\Illuminate\Http\Client\Response $res): string
    {
        return (string) ($res->json('errors.0.message') ?? ('HTTP ' . $res->status()));
    }
}
