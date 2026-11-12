<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ServerSuite\Jobs\SendNotificationJob;
use ServerSuite\Services\Notifier;
use ServerSuite\Exceptions\SubdomainException;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;
use ServerSuite\Models\SubdomainDomain;
use ServerSuite\Services\SubdomainService;

/** #14: Cloudflare subdomains (A + SRV) for game servers. */

const CF = 'https://api.cloudflare.com/client/v4';

function cfServer(string $uuid = 'srv-1', ?string $ip = '203.0.113.10', ?int $port = 25565, ?string $alias = null): object
{
    return (object) [
        'uuid'       => $uuid,
        'allocation' => $ip === null ? null : (object) ['ip' => $ip, 'ip_alias' => $alias, 'port' => $port],
    ];
}

/**
 * Fake Cloudflare: zone lookup + record create/update/delete, recording
 * requests. Http::fake() stubs stack, so failure is a switch, not a re-fake.
 */
function fakeCloudflare(): void
{
    $n = 0;
    $GLOBALS['cfSrvFails'] = false;
    $GLOBALS['cfVerify'] = null;
    $GLOBALS['cfAuthFails'] = false;
    // Records in the fake zone (#117/#132): id => [type, name]; and a switch for failing DELETEs.
    $GLOBALS['cfRecords'] = [];
    $GLOBALS['cfDeleteFails'] = false;
    Http::fake(function (Request $r) use (&$n) {
        $srvFails = $GLOBALS['cfSrvFails'];
        $url = $r->url();
        // Token health (#139): null = active with no expiry, 'down' = 503,
        // 'account' = an account-owned token (/user/tokens/verify says invalid).
        $verify = $GLOBALS['cfVerify'] ?? null;
        $authFails = $GLOBALS['cfAuthFails'] ?? false;
        if ($verify === 'down') {
            return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'Service unavailable']]], 503);
        }
        if ($authFails && ! str_ends_with($url, '/user/tokens/verify')) {
            // What Cloudflare returns for an expired token, and for one that isn't a token at all.
            return $authFails === 'malformed'
                ? Http::response(['success' => false, 'errors' => [['code' => 9106, 'message' => 'Authentication failed (status: 400)']]], 400)
                : Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403);
        }
        if (str_ends_with($url, '/user/tokens/verify')) {
            return match (true) {
                $verify === null    => Http::response(['success' => true, 'result' => ['id' => 'tok', 'status' => 'active']]),
                $verify === 'account' => Http::response(['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]], 401),
                default             => Http::response(['success' => true, 'result' => ['id' => 'tok'] + $verify]),
            };
        }
        if (str_starts_with($url, CF . '/zones?')) {
            if (str_contains($url, 'name=bad.example')) {
                return Http::response(['success' => true, 'result' => []]);
            }
            // A token for a zone deleted and added again: same name, new id (#140).
            $id = $r->hasHeader('Authorization', 'Bearer recreated-token') ? 'recreated' : (str_contains($url, 'own.example') ? 'own' : 'panel');

            return Http::response(['success' => true, 'result' => [['id' => 'zone-' . $id]]]);
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($r->method() === 'GET' && str_ends_with($path, '/dns_records')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            $rows = array_values(array_filter($GLOBALS['cfRecords'], fn ($rec) => $rec[1] === ($q['name'] ?? null)));

            return Http::response(['success' => true, 'result' => array_map(fn ($rec) => ['type' => $rec[0], 'name' => $rec[1]], $rows)]);
        }
        $id = preg_match('#/dns_records/([^/?]+)$#', $path, $m) ? $m[1] : null;
        if ($r->method() === 'DELETE' && $id !== null && $GLOBALS['cfDeleteFails']) {
            return Http::response(['success' => false, 'errors' => [['code' => 0, 'message' => 'Internal error']]], 500);
        }
        if ($id !== null && $r->method() !== 'POST' && ! isset($GLOBALS['cfRecords'][$id])) {
            return Http::response(['success' => false, 'errors' => [['code' => 81044, 'message' => 'Record does not exist.']]], 404);
        }
        if ($r->method() === 'GET' && $id !== null) {
            return Http::response(['success' => true, 'result' => ['id' => $id, 'type' => $GLOBALS['cfRecords'][$id][0], 'name' => $GLOBALS['cfRecords'][$id][1]]]);
        }
        if ($r->method() === 'DELETE' && $id !== null) {
            unset($GLOBALS['cfRecords'][$id]);

            return Http::response(['success' => true, 'result' => ['id' => $id]]);
        }
        if ($r->method() === 'PATCH' && $id !== null) {
            $GLOBALS['cfRecords'][$id] = [$r['type'], $r['name']];

            return Http::response(['success' => true, 'result' => ['id' => $id]]);
        }
        if ($r->method() === 'POST') {
            $type = $r['type'];
            if ($type === 'SRV' && $srvFails) {
                return Http::response(['success' => false, 'errors' => [['message' => 'SRV rejected']]], 400);
            }
            if ($type === 'A' && filter_var($r['content'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                return Http::response(['success' => false, 'errors' => [['code' => 9005, 'message' => 'Content for A record must be a valid IPv4 address.']]], 400);
            }
            $newId = strtolower($type) . '-' . (++$n);
            $GLOBALS['cfRecords'][$newId] = [$type, $r['name']];

            return Http::response(['success' => true, 'result' => ['id' => $newId]]);
        }

        return Http::response(['success' => true, 'result' => ['id' => 'x']]);
    });
}

function cfService(): SubdomainService
{
    return new SubdomainService();
}

function panelDomain(): SubdomainDomain
{
    return cfService()->addDomain('play.example.com', 'panel-token');
}

function allow(string $uuid, int $n): void
{
    cfService()->setAllowance($uuid, $n);
    // The bare test servers have no egg: a stored loader makes them Minecraft (#79).
    $setting = ModManagerSetting::forServer($uuid);
    if (! $setting->loader) {
        $setting->forceFill(['loader' => 'paper'])->save();
    }
}

/** A Rust server (#79): Steam app id 258550, no SRV lookup. */
function cfRust(int $port = 28015): object
{
    return (object) [
        'uuid'       => 'rust-1',
        'egg_id'     => 0,
        'allocation' => (object) ['ip' => '203.0.113.20', 'ip_alias' => null, 'port' => $port],
        'variables'  => collect([(object) ['env_variable' => 'SRCDS_APPID', 'server_value' => null, 'default_value' => '258550']]),
    ];
}

beforeEach(function () {
    fakeCloudflare();
    \ServerSuite\Services\GameDetectionService::flush();
});

it('gives a Rust subdomain an A record only and shows name:port', function () {
    $d = panelDomain();
    cfService()->setAllowance('rust-1', 1);

    $sub = cfService()->claim(cfRust(), 'rusty', $d->id);

    expect($sub->fqdn)->toBe('rusty.play.example.com')->and($sub->srv_record_id)->toBeNull();
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && ($r['type'] ?? null) === 'SRV');
    $o = cfService()->overview(cfRust());
    expect($o['game'])->toBe(['name' => 'Rust', 'srv' => false])
        ->and($o['status_cards'])->toBeFalse()
        ->and($o['items'][0]['address'])->toBe('rusty.play.example.com:28015');
});

it('moves a Rust subdomain by updating only the A record', function () {
    $d = panelDomain();
    cfService()->setAllowance('rust-1', 1);
    $sub = cfService()->claim(cfRust(), 'rusty', $d->id);

    cfService()->repoint(cfRust(28020), $sub->id);

    $patches = collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'PATCH');
    expect($patches)->toHaveCount(1)
        ->and($patches->first()[0]['type'])->toBe('A')
        ->and($sub->fresh()->port)->toBe(28020);
});

it('keeps the SRV record and the name-only address on Minecraft', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    cfService()->claim(cfServer(), 'mysmp', $d->id);

    $o = cfService()->overview(cfServer());
    expect($o['game'])->toBe(['name' => 'Minecraft', 'srv' => true])
        ->and($o['items'][0]['address'])->toBe('mysmp.play.example.com');
});

it('adds an admin domain after checking the token, and stores the token encrypted', function () {
    $d = panelDomain();

    expect($d->zone)->toBe('play.example.com')->and($d->zone_id)->toBe('zone-panel');
    $raw = DB::table('server_suite_domains')->where('id', $d->id)->value('token');
    expect($raw)->not->toBe('panel-token')->and(decrypt($raw, false))->toBe('panel-token');
    expect($d->toArray())->not->toHaveKey('token');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer panel-token'));
});

it('refuses a domain the token cannot see', function () {
    expect(fn () => cfService()->addDomain('bad.example', 't'))->toThrow(SubdomainException::class, "couldn't find the zone");
    expect(SubdomainDomain::count())->toBe(0);
});

it('creates a DNS-only A record and a SRV record carrying the port', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    $sub = cfService()->claim(cfServer(), 'MySMP', $d->id, 7);

    expect($sub->fqdn)->toBe('mysmp.play.example.com')
        ->and($sub->a_record_id)->toBe('a-1')->and($sub->srv_record_id)->toBe('srv-2')
        ->and($sub->ip)->toBe('203.0.113.10')->and($sub->port)->toBe(25565)->and($sub->created_by)->toBe(7);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'A'
        && $r['name'] === 'mysmp.play.example.com' && $r['content'] === '203.0.113.10'
        && $r['proxied'] === false && $r['ttl'] === 300 && str_contains($r->url(), '/zones/zone-panel/dns_records'));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'SRV'
        && $r['name'] === '_minecraft._tcp.mysmp.play.example.com'
        && $r['data'] === ['priority' => 0, 'weight' => 5, 'port' => 25565, 'target' => 'mysmp.play.example.com']);
});

it('points at the allocation alias when one is set', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    expect(cfService()->claim(cfServer(alias: 'play.host.net'), 'smp', $d->id)->ip)->toBe('play.host.net');
});

it('rolls back the A record when the SRV record fails', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $GLOBALS['cfSrvFails'] = true;

    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(SubdomainException::class, 'SRV rejected');
    expect(Subdomain::count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/dns_records/a-1'));
});

it('enforces the admin-set allowance (default 0)', function () {
    $d = panelDomain();

    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(SubdomainException::class, 'no subdomain allowance');

    allow('srv-1', 2);
    cfService()->claim(cfServer(), 'one', $d->id);
    cfService()->claim(cfServer(), 'two', $d->id);
    expect(fn () => cfService()->claim(cfServer(), 'three', $d->id))->toThrow(SubdomainException::class, 'allowance is 2');
});

it('rejects invalid, reserved and taken labels', function () {
    $d = panelDomain();
    allow('srv-1', 5);
    allow('srv-2', 5);

    foreach (['-bad', 'bad-', 'has space', 'a.b', str_repeat('a', 64), ''] as $label) {
        expect(fn () => cfService()->claim(cfServer(), $label, $d->id))->toThrow(SubdomainException::class, 'Use 1-63 characters');
    }
    expect(fn () => cfService()->claim(cfServer(), 'panel', $d->id))->toThrow(SubdomainException::class, 'reserved');

    cfService()->claim(cfServer('srv-2'), 'smp', $d->id);
    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(SubdomainException::class, 'already taken');
});

it('refuses a server with no primary allocation, and a disabled domain', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    expect(fn () => cfService()->claim(cfServer(ip: null), 'smp', $d->id))->toThrow(SubdomainException::class, 'no primary allocation');

    cfService()->setDomainEnabled($d->id, false);
    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(SubdomainException::class, 'not available');
});

it("uses the server's own domain and token, which is write-only", function () {
    allow('srv-1', 1);
    cfService()->saveOwnDomain(cfServer(), 'Own.Example.', 'own-token');

    $sub = cfService()->claim(cfServer(), 'smp', 'own');
    expect($sub->fqdn)->toBe('smp.own.example')->and($sub->domain_id)->toBeNull();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->hasHeader('Authorization', 'Bearer own-token')
        && str_contains($r->url(), '/zones/zone-own/'));

    $overview = cfService()->overview(cfServer());
    expect(json_encode($overview))->not->toContain('own-token');
    expect(ModManagerSetting::forServer('srv-1')->toArray())->not->toHaveKey('own_dns_token');

    // A blank token keeps the stored one; the domain can't change or go while it has subdomains.
    expect(fn () => cfService()->saveOwnDomain(cfServer(), 'other.example', null))->toThrow(SubdomainException::class, 'before changing it');
    expect(fn () => cfService()->removeOwnDomain(cfServer()))->toThrow(SubdomainException::class, 'first');
});

it('releases a subdomain by deleting both records', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);

    cfService()->release(cfServer(), $sub->id);

    expect(Subdomain::count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/a-1'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/srv-2'));
});

it("can't release or repoint another server's subdomain", function () {
    $d = panelDomain();
    allow('srv-2', 1);
    $sub = cfService()->claim(cfServer('srv-2'), 'smp', $d->id);

    expect(fn () => cfService()->release(cfServer('srv-1'), $sub->id))->toThrow(SubdomainException::class, 'not found');
    expect(fn () => cfService()->repoint(cfServer('srv-1'), $sub->id))->toThrow(SubdomainException::class, 'not found');
    expect(Subdomain::count())->toBe(1);
});

it('flags a changed allocation as stale and repoints both records', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);

    $moved = cfServer(ip: '198.51.100.7', port: 25570);
    expect(cfService()->overview($moved)['items'][0]['stale'])->toBeTrue();

    cfService()->repoint($moved, $sub->id);

    expect($sub->refresh()->ip)->toBe('198.51.100.7')->and($sub->port)->toBe(25570);
    expect(cfService()->overview($moved)['items'][0]['stale'])->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/a-1') && $r['content'] === '198.51.100.7');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/srv-2') && $r['data']['port'] === 25570);
});

it("removes a deleted server's records and rows", function () {
    $d = panelDomain();
    allow('srv-1', 2);
    cfService()->claim(cfServer(), 'one', $d->id);
    cfService()->claim(cfServer(), 'two', $d->id);

    cfService()->cleanupServer('srv-1');

    expect(Subdomain::count())->toBe(0);
    Http::assertSentCount(1 + 2 + 4 + 4 + 4); // zone lookup, token check (#139), 2x(name + SRV name) lookup (#117), 2x(A+SRV) create, 2x(A+SRV) delete
});

it("forgets a deleted server's own domain, token and allowance (#35)", function () {
    allow('srv-1', 1);
    cfService()->saveOwnDomain(cfServer(), 'own.example', 'own-token');
    cfService()->claim(cfServer(), 'smp', 'own');

    cfService()->cleanupServer('srv-1');

    expect(Subdomain::count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->hasHeader('Authorization', 'Bearer own-token'));
    $raw = DB::table('mod_manager_settings')->where('server_uuid', 'srv-1')->first();
    expect($raw->own_dns_zone)->toBeNull()->and($raw->own_dns_token)->toBeNull()->and((int) $raw->subdomain_limit)->toBe(0);
});

it("won't remove an admin domain that still has subdomains", function () {
    $d = panelDomain();
    allow('srv-1', 1);
    cfService()->claim(cfServer(), 'smp', $d->id);

    expect(fn () => cfService()->removeDomain($d->id))->toThrow(SubdomainException::class, 'still has 1');
});

it("replaces an admin domain's token and keeps its subdomains (#140)", function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);

    expect(fn () => cfService()->replaceDomainToken($d->id, '  '))->toThrow(SubdomainException::class, 'Enter the new Cloudflare API token')
        ->and(fn () => cfService()->replaceDomainToken($d->id, 'recreated-token'))->toThrow(SubdomainException::class, 'different play.example.com zone')
        ->and(fn () => cfService()->replaceDomainToken(999, 'x'))->toThrow(SubdomainException::class, 'Domain not found');

    cfService()->replaceDomainToken($d->id, ' rotated-panel-token ');

    expect(SubdomainDomain::find($d->id)->token)->toBe('rotated-panel-token')
        ->and(DB::table('server_suite_domains')->where('id', $d->id)->value('token'))->not->toBe('rotated-panel-token')
        ->and(Subdomain::count())->toBe(1);

    cfService()->release(cfServer(), $sub->id);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/a-1') && $r->hasHeader('Authorization', 'Bearer rotated-panel-token'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/srv-2') && $r->hasHeader('Authorization', 'Bearer rotated-panel-token'));
});

it('lets a token for a recreated zone replace one on a domain with no subdomains (#140)', function () {
    $d = panelDomain();

    cfService()->replaceDomainToken($d->id, 'recreated-token');

    expect(SubdomainDomain::find($d->id)->zone_id)->toBe('zone-recreated');
});

it("checks a new own-domain token against the zone its subdomains are in (#140)", function () {
    allow('srv-1', 1);
    cfService()->saveOwnDomain(cfServer(), 'own.example', 'own-token');
    cfService()->claim(cfServer(), 'smp', 'own');

    expect(fn () => cfService()->saveOwnDomain(cfServer(), 'own.example', 'recreated-token'))
        ->toThrow(SubdomainException::class, 'different own.example zone');

    cfService()->saveOwnDomain(cfServer(), 'own.example', 'rotated-own-token');
    expect(ModManagerSetting::forServer('srv-1')->own_dns_token)->toBe('rotated-own-token');
});

it('lists domains and allowance for the Subdomains screen, without tokens', function () {
    $d = panelDomain();
    allow('srv-1', 3);

    $o = cfService()->overview(cfServer());

    expect($o['allowance'])->toBe(3)->and($o['used'])->toBe(0)
        ->and($o['domains'])->toBe([['id' => $d->id, 'zone' => 'play.example.com']])
        ->and($o['own'])->toBe(['zone' => null, 'configured' => false])
        ->and(json_encode($o))->not->toContain('panel-token');
});

// ---------------------------------------------------------------------------
// Token health (#139)
// ---------------------------------------------------------------------------

function adminTarget(): void
{
    app(Notifier::class)->saveDefaults([['type' => 'ntfy', 'server' => 'http://192.168.5.10:8080', 'topic' => 'panel']], []);
}

it('records the token status and expiry when a domain is added (#139)', function () {
    $GLOBALS['cfVerify'] = ['status' => 'active', 'expires_on' => now()->addDays(60)->toIso8601String()];
    $d = panelDomain();

    expect($d->token_status)->toBe('active')
        ->and($d->token_expires_at->toDateString())->toBe(now()->addDays(60)->toDateString())
        ->and($d->token_checked_at)->not->toBeNull()
        ->and(SubdomainService::tokenWarning($d))->toBeNull();
});

it('warns the admin once when a token is about to expire (#139)', function () {
    adminTarget();
    Queue::fake();
    $d = panelDomain();
    $expires = now()->addDays(10)->addHour();
    $GLOBALS['cfVerify'] = ['status' => 'active', 'expires_on' => $expires->toIso8601String()];

    expect(cfService()->checkDomainTokens())->toHaveCount(1);
    $w = SubdomainService::tokenWarning($d->fresh());
    expect($w['level'])->toBe('warn')
        ->and($w['title'])->toContain('play.example.com expires in 10 days')
        ->and($w['key'])->toBe('expiring:' . $expires->format('Y-m-d'));

    Cache::flush();   // the next day: the cooldown is gone, the stored warning still stops a repeat
    cfService()->checkDomainTokens();
    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn ($j) => str_contains(json_encode($j), 'expires in'));
});

it('reports an expired token, then warns again for the next problem (#139)', function () {
    adminTarget();
    Queue::fake();
    $d = panelDomain();
    $GLOBALS['cfVerify'] = ['status' => 'expired', 'expires_on' => now()->subDay()->toIso8601String()];

    cfService()->checkDomainTokens();
    $d->refresh();
    expect($d->token_status)->toBe('expired')
        ->and(SubdomainService::tokenWarning($d)['short'])->toBe('Token has expired')
        ->and($d->token_warned)->toBe('expired');

    $GLOBALS['cfVerify'] = ['status' => 'disabled'];
    cfService()->checkDomainTokens();
    Queue::assertPushed(SendNotificationJob::class, 2);
});

it('treats a token past its expiry date as expired before the next check (#139)', function () {
    $d = panelDomain();
    $d->forceFill(['token_status' => 'active', 'token_expires_at' => now()->subMinute()])->save();

    expect(SubdomainService::tokenWarning($d)['key'])->toBe('expired');
});

it('keeps the last known state while Cloudflare is down (#139)', function () {
    $GLOBALS['cfVerify'] = ['status' => 'expired'];
    $d = panelDomain();
    $GLOBALS['cfVerify'] = 'down';

    cfService()->checkDomainTokens();

    expect($d->fresh()->token_status)->toBe('expired');
});

it("checks an account-owned token by reading the zone, and flags one that can't see it (#139)", function () {
    $GLOBALS['cfVerify'] = 'account';
    $d = panelDomain();
    expect($d->token_status)->toBe('active')->and($d->token_expires_at)->toBeNull();

    $d->forceFill(['zone' => 'bad.example'])->save();   // the zone lookup now finds nothing
    cfService()->checkDomainToken($d);
    expect($d->fresh()->token_status)->toBe('invalid');
});

it('tells the owner to ask an admin when the panel token is rejected, and records it (#139)', function () {
    adminTarget();
    Queue::fake();
    $d = panelDomain();
    allow('srv-1', 1);
    $GLOBALS['cfAuthFails'] = true;
    $GLOBALS['cfVerify'] = ['status' => 'expired'];

    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))
        ->toThrow(SubdomainException::class, 'Ask a panel admin to replace the token');
    expect($d->fresh()->token_status)->toBe('expired')
        ->and(Subdomain::count())->toBe(0);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

it('treats a malformed token (HTTP 400, code 9106) as rejected (#139)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $GLOBALS['cfAuthFails'] = 'malformed';

    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))
        ->toThrow(SubdomainException::class, 'Ask a panel admin to replace the token');
    expect($d->fresh()->token_status)->toBe('invalid');
});

it("tells the owner to replace their own domain's token when Cloudflare rejects it (#139)", function () {
    allow('srv-1', 1);
    cfService()->saveOwnDomain(cfServer(), 'own.example', 'own-token');
    $GLOBALS['cfAuthFails'] = true;

    expect(fn () => cfService()->claim(cfServer(), 'smp', 'own'))
        ->toThrow(SubdomainException::class, "rejected your own domain's API token");
});

it('clears the warning when the token is replaced (#139)', function () {
    $GLOBALS['cfVerify'] = ['status' => 'expired'];
    $d = panelDomain();
    $GLOBALS['cfVerify'] = ['status' => 'active', 'expires_on' => now()->addYear()->toIso8601String()];

    $d = cfService()->replaceDomainToken($d->id, 'rotated-panel-token');

    expect($d->token_status)->toBe('active')
        ->and($d->token_warned)->toBeNull()
        ->and(SubdomainService::tokenWarning($d))->toBeNull();
});

it('runs the daily token check from the scheduler command (#139)', function () {
    panelDomain();
    $GLOBALS['cfVerify'] = ['status' => 'expired'];

    $this->artisan('serversuite:check-dns-tokens')->expectsOutputToContain('play.example.com has expired')->assertSuccessful();
});

// ---- #117: names already in the zone, deletes that didn't happen ----------

it('does not claim a name the zone already uses (#117)', function () {
    $d = panelDomain();
    allow('srv-1', 2);
    $GLOBALS['cfRecords']['admin-a'] = ['A', 'shop.play.example.com'];
    $GLOBALS['cfRecords']['admin-srv'] = ['SRV', '_minecraft._tcp.wiki.play.example.com'];

    expect(fn () => cfService()->claim(cfServer(), 'shop', $d->id))->toThrow(SubdomainException::class, 'shop.play.example.com already has DNS records (A)')
        ->and(fn () => cfService()->claim(cfServer(), 'wiki', $d->id))->toThrow(SubdomainException::class, 'wiki.play.example.com already has DNS records (SRV)');
    expect(Subdomain::count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

    expect(cfService()->claim(cfServer(), 'free', $d->id)->fqdn)->toBe('free.play.example.com');
});

it('refuses the claim when the name check fails (#117)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $GLOBALS['cfVerify'] = 'down';

    expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(SubdomainException::class, "Couldn't check smp.play.example.com");
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('keeps the subdomain when Cloudflare does not delete its records (#117)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);

    $GLOBALS['cfDeleteFails'] = true;
    expect(fn () => cfService()->release(cfServer(), $sub->id))->toThrow(SubdomainException::class, "Couldn't remove the DNS records for smp.play.example.com");
    expect(Subdomain::count())->toBe(1);

    $GLOBALS['cfDeleteFails'] = false;
    cfService()->release(cfServer(), $sub->id);
    expect(Subdomain::count())->toBe(0)->and($GLOBALS['cfRecords'])->toBe([]);
});

it('releases records that were already removed by hand (#117)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);
    $GLOBALS['cfRecords'] = [];

    cfService()->release(cfServer(), $sub->id);
    expect(Subdomain::count())->toBe(0);
});

it("retries a deleted server's records that could not be removed (#117)", function () {
    panelTables();
    $d = panelDomain();
    allow('srv-1', 2);
    cfService()->saveOwnDomain(cfServer(), 'own.example', 'own-token');
    cfService()->claim(cfServer(), 'one', $d->id);
    cfService()->claim(cfServer(), 'two', 'own');

    $GLOBALS['cfDeleteFails'] = true;
    cfService()->cleanupServer('srv-1');
    expect(Subdomain::count())->toBe(2)
        ->and(ModManagerSetting::forServer('srv-1')->own_dns_token)->not->toBeNull();

    $GLOBALS['cfDeleteFails'] = false;
    $this->artisan('serversuite:retry-subdomain-cleanup')->assertSuccessful();
    expect(Subdomain::count())->toBe(0)
        ->and($GLOBALS['cfRecords'])->toBe([])
        ->and(ModManagerSetting::forServer('srv-1')->own_dns_token)->toBeNull();
});

it('removes the records when saving the claim fails (#117)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    Subdomain::creating(fn () => throw new RuntimeException('database went away'));

    try {
        expect(fn () => cfService()->claim(cfServer(), 'smp', $d->id))->toThrow(RuntimeException::class, 'database went away');
    } finally {
        Subdomain::flushEventListeners();
    }
    expect($GLOBALS['cfRecords'])->toBe([]);
});

// ---- #132: IPv6 and host name aliases -------------------------------------

it('gives an IPv6 allocation an AAAA record (#132)', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    expect(cfService()->claim(cfServer(ip: '2001:db8::10'), 'smp', $d->id)->ip)->toBe('2001:db8::10');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'AAAA' && $r['content'] === '2001:db8::10' && $r['proxied'] === false);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'SRV' && $r['data']['target'] === 'smp.play.example.com');
});

it('gives a host name alias a CNAME, and the SRV record targets it (#132)', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    cfService()->claim(cfServer(alias: 'Node1.Host.net.'), 'smp', $d->id);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'CNAME' && $r['name'] === 'smp.play.example.com' && $r['content'] === 'node1.host.net' && $r['proxied'] === false);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['type'] === 'SRV' && $r['data']['target'] === 'node1.host.net');
});

it('refuses an alias that is neither an IP nor a host name before any record (#132)', function () {
    $d = panelDomain();
    allow('srv-1', 1);

    expect(fn () => cfService()->claim(cfServer(alias: 'not a host'), 'smp', $d->id))->toThrow(SubdomainException::class, 'is not an IP address or a host name');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('replaces the A record with a CNAME when the server moves to a host name (#132)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);

    cfService()->repoint(cfServer(alias: 'node1.host.net', port: 25570), $sub->id);
    $sub->refresh();
    expect($GLOBALS['cfRecords'])->not->toHaveKey('a-1')
        ->and($GLOBALS['cfRecords'][$sub->a_record_id])->toBe(['CNAME', 'smp.play.example.com'])
        ->and([$sub->ip, $sub->port])->toBe(['node1.host.net', 25570]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/srv-2') && $r['data']['target'] === 'node1.host.net');

    $id = $sub->a_record_id;
    cfService()->repoint(cfServer(alias: 'node2.host.net', port: 25570), $sub->id);
    expect($sub->fresh()->a_record_id)->toBe($id);
});

it('recreates an address record removed by hand on the next move (#132)', function () {
    $d = panelDomain();
    allow('srv-1', 1);
    $sub = cfService()->claim(cfServer(), 'smp', $d->id);
    unset($GLOBALS['cfRecords']['a-1']);

    cfService()->repoint(cfServer(ip: '198.51.100.7'), $sub->id);
    $id = $sub->fresh()->a_record_id;
    expect($id)->not->toBe('a-1')->and($GLOBALS['cfRecords'][$id])->toBe(['A', 'smp.play.example.com']);
});
