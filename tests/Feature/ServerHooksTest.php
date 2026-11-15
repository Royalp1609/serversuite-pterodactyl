<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\SubdomainService;

/**
 * #35: the Server model hooks wait for the panel's transaction to commit.
 * #37: a new primary allocation moves DNS only once the server has started on it.
 */

beforeEach(function () {
    panelTables();

    $GLOBALS['hookCalls'] = 0;
    Http::fake(function (Request $r) {
        $GLOBALS['hookCalls']++;
        if (str_contains($r->url(), '/zones?')) {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-1']]]);
        }
        // A name lookup (#117): the name is free.
        if ($r->method() === 'GET' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/dns_records')) {
            return Http::response(['success' => true, 'result' => []]);
        }

        return Http::response(['success' => true, 'result' => ['id' => strtolower((string) ($r['type'] ?? 'x')) . '-' . $GLOBALS['hookCalls']]]);
    });
});

function hookedServer(): Server
{
    $alloc = Allocation::query()->create(['ip' => '203.0.113.10', 'port' => 25565]);
    $server = Server::query()->create(['uuid' => 'srv-h', 'allocation_id' => $alloc->id]);

    // A Minecraft server (#79), so its subdomain gets the SRV record these tests move.
    \ServerSuite\Services\GameDetectionService::flush();
    \ServerSuite\Models\ModManagerSetting::forServer('srv-h')->forceFill(['loader' => 'paper'])->save();

    $svc = app(SubdomainService::class);
    $svc->setAllowance('srv-h', 1);
    $svc->saveOwnDomain($server->load('allocation'), 'own.example', 'own-token');
    $svc->claim($server, 'smp', 'own');

    return $server;
}

it("deletes a server's subdomains and forgets its own-domain token once the delete commits", function () {
    $server = hookedServer();

    DB::transaction(function () use ($server) {
        $server->delete();
        expect(Subdomain::count())->toBe(1, 'nothing happens before the commit');
    });

    expect(Subdomain::count())->toBe(0);
    $setting = ModManagerSetting::forServer('srv-h');
    expect($setting->own_dns_zone)->toBeNull()
        ->and($setting->own_dns_token)->toBeNull()
        ->and((int) $setting->subdomain_limit)->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE');
});

it('keeps DNS and the token when the delete rolls back', function () {
    $server = hookedServer();
    $before = $GLOBALS['hookCalls'];

    try {
        DB::transaction(function () use ($server) {
            $server->delete();
            throw new RuntimeException('panel delete failed');
        });
    } catch (RuntimeException) {
    }

    expect(Subdomain::count())->toBe(1)
        ->and(ModManagerSetting::forServer('srv-h')->own_dns_token)->toBe('own-token')
        ->and($GLOBALS['hookCalls'])->toBe($before, 'no Cloudflare calls');
});

/** A service whose "Wings" says the server started at $started (null: not running). */
function wings(?DateTimeInterface &$started, int &$asked = 0): SubdomainService
{
    return new SubdomainService(null, function () use (&$started, &$asked) {
        $asked++;

        return $started;
    });
}

it('moves DNS to a new primary allocation only after the server restarts on it', function () {
    $server = hookedServer();
    $sub = Subdomain::query()->firstOrFail();
    $new = Allocation::query()->create(['ip' => '198.51.100.9', 'ip_alias' => '203.0.113.99', 'port' => 25599]);

    $this->travel(-10)->minutes();
    $started = now();   // running since before the change: Docker may answer on the new port, the game isn't on it
    $this->travelBack();
    DB::transaction(fn () => $server->update(['allocation_id' => $new->id]));

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    expect($sub->fresh()->move_pending_since)->not->toBeNull();
    $svc = wings($started);
    expect($svc->applyPendingMoves())->toBe(0)
        ->and($sub->fresh()->port)->toBe(25565, 'not restarted since the change');

    $started = null;   // stopped / Wings unreachable
    expect($svc->applyPendingMoves())->toBe(0);

    $this->travel(1)->minutes();
    $started = now();   // restarted on the new allocation
    expect($svc->applyPendingMoves())->toBe(1);
    $sub = $sub->fresh();
    expect([$sub->ip, $sub->port])->toBe(['203.0.113.99', 25599])
        ->and($sub->move_pending_since)->toBeNull();
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && ($r->data()['data']['port'] ?? null) === 25599);

    expect($svc->applyPendingMoves())->toBe(0, 'nothing left to move');
});

it('leaves DNS alone when the allocation is switched back before a restart', function () {
    $server = hookedServer();
    $sub = Subdomain::query()->firstOrFail();
    $first = $server->allocation_id;
    $new = Allocation::query()->create(['ip' => '198.51.100.9', 'port' => 25599]);

    $server->update(['allocation_id' => $new->id]);
    $server->update(['allocation_id' => $first]);

    $started = now()->addMinute();
    $asked = 0;
    expect(wings($started, $asked)->applyPendingMoves())->toBe(0)
        ->and($asked)->toBe(0, 'nothing waiting, Wings not asked')
        ->and($sub->fresh()->move_pending_since)->toBeNull('the mark is cleared');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
});

it('waits for the next start when the allocation changed without the hook', function () {
    $server = hookedServer();
    $sub = Subdomain::query()->firstOrFail();
    $new = Allocation::query()->create(['ip' => '198.51.100.9', 'port' => 25599]);
    DB::table('servers')->where('id', $server->id)->update(['allocation_id' => $new->id]);   // no model events: plugin was off

    $started = now()->subMinute();
    $svc = wings($started);
    expect($svc->applyPendingMoves())->toBe(0, 'first sight only marks it')
        ->and($sub->fresh()->move_pending_since)->not->toBeNull()
        ->and($svc->applyPendingMoves())->toBe(0, 'started before it was noticed');

    $this->travel(2)->minutes();
    $started = now();
    expect($svc->applyPendingMoves())->toBe(1)
        ->and($sub->fresh()->port)->toBe(25599);
});

it("doesn't mark a move when the allocation change rolls back, or for other updates", function () {
    $server = hookedServer();
    $new = Allocation::query()->create(['ip' => '198.51.100.9', 'port' => 25599]);

    try {
        DB::transaction(function () use ($server, $new) {
            $server->update(['allocation_id' => $new->id]);
            throw new RuntimeException('build change failed');
        });
    } catch (RuntimeException) {
    }
    $server->update(['uuid' => 'srv-h']);
    $server->touch();

    expect(Subdomain::query()->firstOrFail()->move_pending_since)->toBeNull();
});

it('looks only at waiting moves between full scans (#141 item 59)', function () {
    $server = hookedServer();
    $sub = Subdomain::query()->firstOrFail();
    $new = Allocation::query()->create(['ip' => '198.51.100.9', 'port' => 25599]);
    DB::table('servers')->where('id', $server->id)->update(['allocation_id' => $new->id]);   // no hook

    $started = now()->subMinute();
    $asked = 0;
    expect(wings($started, $asked)->applyPendingMoves(false))->toBe(0)
        ->and($sub->fresh()->move_pending_since)->toBeNull('not noticed on a quick pass')
        ->and($asked)->toBe(0);

    wings($started)->applyPendingMoves(true);
    expect($sub->fresh()->move_pending_since)->not->toBeNull('a full scan notices it');
});
