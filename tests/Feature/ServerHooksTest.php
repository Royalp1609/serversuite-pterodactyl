<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\SubdomainService;

/** #35: the Server model hooks wait for the panel's transaction to commit. */

beforeEach(function () {
    Schema::create('allocations', function (Blueprint $t) {
        $t->id();
        $t->string('ip');
        $t->string('ip_alias')->nullable();
        $t->unsignedInteger('port');
        $t->timestamps();
    });
    Schema::create('servers', function (Blueprint $t) {
        $t->id();
        $t->string('uuid');
        $t->unsignedBigInteger('allocation_id')->nullable();
        $t->timestamps();
    });

    $GLOBALS['hookCalls'] = 0;
    Http::fake(function (Request $r) {
        $GLOBALS['hookCalls']++;
        if (str_contains($r->url(), '/zones?')) {
            return Http::response(['success' => true, 'result' => [['id' => 'zone-1']]]);
        }

        return Http::response(['success' => true, 'result' => ['id' => strtolower((string) ($r['type'] ?? 'x')) . '-' . $GLOBALS['hookCalls']]]);
    });
});

function hookedServer(): Server
{
    $alloc = Allocation::query()->create(['ip' => '203.0.113.10', 'port' => 25565]);
    $server = Server::query()->create(['uuid' => 'srv-h', 'allocation_id' => $alloc->id]);

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
