<?php

use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ServerModPendingUpdate;
use ServerSuite\Services\ModManagerService;

/** #121: updates leave disabled mods, pack mods and dismissed updates alone. */

function usMod(string $id, array $extra = [], ?bool $dismissed = false): ServerMod
{
    $mod = ServerMod::query()->create(array_merge([
        'server_uuid' => 'srv-us', 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => "Mod {$id}",
        'installed_version' => '1', 'target_filename' => strtolower($id) . '.jar', 'install_path' => '/mods',
    ], $extra));
    if ($dismissed !== null) {
        ServerModPendingUpdate::query()->create(['server_mod_id' => $mod->id, 'available_version' => '2', 'dismissed' => $dismissed]);
    }

    return $mod;
}

it('refuses to update a disabled mod or a pack mod', function () {
    $svc = app(ModManagerService::class);
    $server = (object) ['uuid' => 'srv-us', 'id' => 1];

    expect(fn () => $svc->update(usMod('DIS', ['disabled_at' => now()]), $server))
        ->toThrow(DeliveryException::class, 'Enable it before updating it')
        ->and(fn () => $svc->update(usMod('PACK', ['modpack_id' => 7]), $server))
        ->toThrow(DeliveryException::class, 'updates with the pack');
});

it('update all only tries enabled, non-pack mods with an undismissed update', function () {
    usMod('DIS', ['disabled_at' => now()]);
    usMod('PACK', ['modpack_id' => 7]);
    usMod('GONE', [], true);
    usMod('NONE', [], null);
    usMod('OK');

    $svc = Mockery::mock(ModManagerService::class)->makePartial();
    $tried = [];
    $svc->shouldReceive('update')->andReturnUsing(function (ServerMod $m) use (&$tried) {
        $tried[] = $m->source_id;

        return $m;
    });

    $result = $svc->updateAll((object) ['uuid' => 'srv-us', 'id' => 1]);

    expect($tried)->toBe(['OK'])
        ->and(array_column($result['updated'], 'mod_name'))->toBe(['Mod OK']);
});
