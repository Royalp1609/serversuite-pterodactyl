<?php

namespace ServerSuite\Dns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DnsProvider;

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
            throw new \RuntimeException(
                "Cloudflare couldn't find the zone {$this->zone} with this token. "
                . 'Check the zone name and that the token has Zone > DNS > Edit for it.'
            );
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
            throw new \RuntimeException('Cloudflare A record failed: ' . self::error($a));
        }
        $aId = (string) $a->json('result.id');

        if ($srvService === null) {
            return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => null];
        }

        $srv = $this->http()->post($base, $this->srvRecord($fqdn, $port, $srvService));
        if (! $srv->successful() || ! $srv->json('success')) {
            // Roll back rather than leave a half-configured name.
            $this->deleteRecords($aId, null);
            throw new \RuntimeException('Cloudflare SRV record failed: ' . self::error($srv));
        }

        return ['fqdn' => $fqdn, 'a_record_id' => $aId, 'srv_record_id' => (string) $srv->json('result.id')];
    }

    public function updateRecords(string $fqdn, ?string $aRecordId, ?string $srvRecordId, string $ip, int $port, string $srvService = '_minecraft._tcp'): void
    {
        $base = $this->recordsUrl();

        if ($aRecordId) {
            $res = $this->http()->patch("{$base}/{$aRecordId}", $this->aRecord($fqdn, $ip));
            if (! $res->successful() || ! $res->json('success')) {
                throw new \RuntimeException('Cloudflare A record update failed: ' . self::error($res));
            }
        }

        if ($srvRecordId) {
            $res = $this->http()->patch("{$base}/{$srvRecordId}", $this->srvRecord($fqdn, $port, $srvService));
            if (! $res->successful() || ! $res->json('success')) {
                throw new \RuntimeException('Cloudflare SRV record update failed: ' . self::error($res));
            }
        }
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

    private static function error(\Illuminate\Http\Client\Response $res): string
    {
        return (string) ($res->json('errors.0.message') ?? ('HTTP ' . $res->status()));
    }
}
