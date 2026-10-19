<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
    Http::fake(function (Request $r) use (&$n) {
        $srvFails = $GLOBALS['cfSrvFails'];
        $url = $r->url();
        if (str_starts_with($url, CF . '/zones?')) {
            return str_contains($url, 'name=bad.example')
                ? Http::response(['success' => true, 'result' => []])
                : Http::response(['success' => true, 'result' => [['id' => 'zone-' . (str_contains($url, 'own.example') ? 'own' : 'panel')]]]);
        }
        if ($r->method() === 'POST') {
            $type = $r['type'];
            if ($type === 'SRV' && $srvFails) {
                return Http::response(['success' => false, 'errors' => [['message' => 'SRV rejected']]], 400);
            }

            return Http::response(['success' => true, 'result' => ['id' => strtolower($type) . '-' . (++$n)]]);
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
}

beforeEach(fn () => fakeCloudflare());

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

    cfService()->repointServer($moved);

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
    Http::assertSentCount(1 + 4 + 4); // zone lookup, 2x(A+SRV) create, 2x(A+SRV) delete
});

it("won't remove an admin domain that still has subdomains", function () {
    $d = panelDomain();
    allow('srv-1', 1);
    cfService()->claim(cfServer(), 'smp', $d->id);

    expect(fn () => cfService()->removeDomain($d->id))->toThrow(SubdomainException::class, 'still has 1');
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
