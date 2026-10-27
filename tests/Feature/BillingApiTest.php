<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Route;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Acl\Api\AdminAcl;
use ServerSuite\Http\Controllers\Api\BillingApiController;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;

/**
 * #38: the billing API (subdomain allowance, backup plan), as on Pelican.
 * The panel's middleware and key check are stand-ins here (same check);
 * CI confirms the real routes sit behind application-api on each panel.
 */

beforeEach(function () {
    panelTables();
    AdminAcl::$key = ['servers' => AdminAcl::READ | AdminAcl::WRITE];
    Activity::$logged = [];
    Route::prefix('api/application/serversuite')->group(function () {
        Route::get('servers/{ref}', [BillingApiController::class, 'show']);
        Route::put('servers/{ref}/subdomains', [BillingApiController::class, 'subdomains']);
        Route::put('servers/{ref}/backups', [BillingApiController::class, 'backups']);
    });
    $this->server = Server::query()->create(['uuid' => 'srv-b', 'external_id' => 'svc-42', 'backup_limit' => 3]);
    ModManagerSetting::forServer('srv-b')->forceFill(['loader' => 'paper', 'game_version' => '26.1.2'])->save();
    $this->actingAs((new User())->forceFill(['id' => 5]));
});

function api(string $ref, string $tail = ''): string
{
    return "/api/application/serversuite/servers/{$ref}{$tail}";
}

it('reads the state by panel id and by external id', function () {
    Subdomain::query()->create(['server_uuid' => 'srv-b', 'provider' => 'cloudflare', 'label' => 'smp', 'zone' => 'example.com', 'fqdn' => 'smp.example.com', 'ip' => '1.2.3.4', 'port' => 25565]);

    $byId = $this->getJson(api((string) $this->server->id))->assertOk()->json('attributes');
    $byExternal = $this->getJson(api('external:svc-42'))->assertOk()->json('attributes');

    expect($byId)->toBe($byExternal)
        ->and($byId['subdomains'])->toBe(['allowance' => 0, 'used' => 1, 'over_allowance' => true, 'items' => [['fqdn' => 'smp.example.com', 'own_domain' => true]]])
        ->and($byId['game'])->toBe(['loader' => 'paper', 'minecraft' => '26.1.2'])
        ->and($byId['backups']['backup_limit'])->toBe(3)
        ->and(json_encode($byId))->not->toContain('token');
    $this->getJson(api('external:nope'))->assertNotFound();
    $this->getJson(api('abc'))->assertNotFound();
});

it('sets the allowance idempotently and logs it once as the key owner', function () {
    $this->putJson(api('external:svc-42', '/subdomains'), ['allowance' => 2])->assertOk()->assertJsonPath('attributes.subdomains.allowance', 2);
    $this->putJson(api('external:svc-42', '/subdomains'), ['allowance' => 2])->assertOk();

    expect((int) ModManagerSetting::forServer('srv-b')->subdomain_limit)->toBe(2)
        ->and(Activity::$logged)->toHaveCount(1)
        ->and(Activity::$logged[0]['event'])->toBe('server:serversuite.subdomain-limit-set')
        ->and(Activity::$logged[0]['actor']->id)->toBe(5);
});

it('never removes subdomains when the allowance drops', function () {
    ModManagerSetting::forServer('srv-b')->forceFill(['subdomain_limit' => 2])->save();
    foreach (['a', 'b'] as $l) {
        Subdomain::query()->create(['server_uuid' => 'srv-b', 'provider' => 'cloudflare', 'label' => $l, 'zone' => 'example.com', 'fqdn' => "{$l}.example.com", 'ip' => '1.2.3.4', 'port' => 25565]);
    }

    $this->putJson(api((string) $this->server->id, '/subdomains'), ['allowance' => 1])
        ->assertOk()->assertJsonPath('attributes.subdomains.over_allowance', true);
    expect(Subdomain::query()->count())->toBe(2);
});

it('reports effective_keep as what rotation keeps: the backup limit (#73)', function () {
    // A keep setting doesn't change it: rotation keeps exactly the limit.
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => true, 'interval_hours' => 24, 'keep' => 5])
        ->assertOk()->assertJsonPath('attributes.backups.backup_limit', 3)->assertJsonPath('attributes.backups.effective_keep', 3);

    $this->server->forceFill(['backup_limit' => 0])->save();
    $this->getJson(api('external:svc-42'))->assertOk()->assertJsonPath('attributes.backups.effective_keep', 0);
});

it('sets the backup plan and keeps what a later call leaves out', function () {
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => true, 'interval_hours' => 24, 'hour' => 4, 'keep' => 5])
        ->assertOk()->assertJsonPath('attributes.backups.interval_hours', 24)->assertJsonPath('attributes.backups.hour', 4);
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => true, 'interval_hours' => 24, 'hour' => 4, 'keep' => 5])->assertOk();
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => false])
        ->assertOk()->assertJsonPath('attributes.backups.enabled', false)->assertJsonPath('attributes.backups.interval_hours', 24);

    expect(collect(Activity::$logged)->where('event', 'server:serversuite.backup-plan-set'))->toHaveCount(2, 'set, then off; the repeat is not logged');
});

it('validates input', function () {
    $this->putJson(api('external:svc-42', '/subdomains'), ['allowance' => 101])->assertStatus(422);
    $this->putJson(api('external:svc-42', '/subdomains'), [])->assertStatus(422);
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => true])->assertStatus(422);
    $this->putJson(api('external:svc-42', '/backups'), ['enabled' => true, 'interval_hours' => 24, 'hour' => 24])->assertStatus(422);
});

it('needs the servers read permission to read and write to change', function () {
    AdminAcl::$key = ['servers' => AdminAcl::READ];
    $this->getJson(api('external:svc-42'))->assertOk();
    $this->putJson(api('external:svc-42', '/subdomains'), ['allowance' => 3])->assertForbidden();

    AdminAcl::$key = ['servers' => AdminAcl::NONE];
    $this->getJson(api('external:svc-42'))->assertForbidden();
    expect((int) ModManagerSetting::forServer('srv-b')->subdomain_limit)->toBe(0);
});
