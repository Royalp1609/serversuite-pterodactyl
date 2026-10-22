<?php

use ServerSuite\Models\ServerMod;
use ServerSuite\Services\ModManagerService;

/** Bulk remove: dependents and orphans worked out for the whole selection. */

function brMod(string $id, array $deps = []): ServerMod
{
    return ServerMod::query()->create([
        'server_uuid' => 'srv-br', 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => "Mod {$id}",
        'installed_version' => '1', 'target_filename' => strtolower($id) . '.jar', 'install_path' => '/mods', 'dependencies' => $deps,
    ]);
}

beforeEach(function () {
    $this->lib = brMod('LIB');
    $this->a = brMod('A', ['LIB']);
    $this->b = brMod('B', ['LIB']);
    $this->c = brMod('C', ['A']);
    $this->svc = app(ModManagerService::class);
});

it('orphans a library only once every mod that needs it is going', function () {
    expect($this->svc->orphanedBySet([$this->a]))->toBe([])
        ->and(array_map(fn ($m) => $m->source_id, $this->svc->orphanedBySet([$this->a, $this->b])))->toBe(['LIB'])
        ->and(array_map(fn ($m) => $m->source_id, $this->svc->orphanedBy($this->b)))->toBe([], 'single-mod check unchanged');
});

it('names mods outside the selection that still need one of it', function () {
    expect($this->svc->dependentsOfSet([$this->a, $this->b]))->toBe([['id' => $this->c->id, 'mod_name' => 'Mod C', 'needs' => ['Mod A']]])
        ->and($this->svc->dependentsOfSet([$this->a, $this->c]))->toBe([], 'C is going too');
});
