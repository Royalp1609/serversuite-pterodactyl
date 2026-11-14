<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\LoaderSwitchService;
use ServerSuite\Tests\Fakes\FakeWings;

/** #137: a loader switch records the new loader, and never keeps the old one. */

function lslEgg(string $name): Pterodactyl\Models\Egg
{
    $egg = Pterodactyl\Models\Egg::query()->create(['name' => $name, 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]);
    Pterodactyl\Models\EggVariable::query()->create(['egg_id' => $egg->id, 'env_variable' => 'MINECRAFT_VERSION', 'default_value' => 'latest']);

    return $egg;
}

beforeEach(function () {
    panelTables();
    Http::fake(['*' => Http::response([], 404)]);   // MCJars: no pin
    $this->server = Pterodactyl\Models\Server::query()->create(['uuid' => 'srv-lsl', 'name' => 'LSL']);
    ModManagerSetting::forServer('srv-lsl')->forceFill(['loader' => 'paper', 'install_path' => '/plugins', 'loader_locked' => true])->save();

    app()->instance(DaemonFileClient::class, new FakeWings());
    $ops = Mockery::mock(PanelServerOps::class)->shouldIgnoreMissing();
    $ops->shouldReceive('runtimeState')->andReturn(['power' => 'offline', 'install' => null]);
    app()->instance(PanelServerOps::class, $ops);
    app()->forgetInstance(LoaderSwitchService::class);
});

it('records Folia, Pufferfish and Forge hybrids', function (string $egg, string $loader, string $path) {
    app(LoaderSwitchService::class)->switchLoader($this->server, lslEgg($egg)->id, '1.21.4', false);

    $s = ModManagerSetting::forServer('srv-lsl')->fresh();
    expect([$s->loader, $s->install_path, (bool) $s->loader_locked])->toBe([$loader, $path, false]);
})->with([
    ['Folia', 'folia', '/plugins'],
    ['Pufferfish', 'pufferfish', '/plugins'],
    ['Mohist', 'forge', '/mods'],
    ['NeoForge', 'neoforge', '/mods'],
]);

it('clears the old loader when the new egg is not recognised', function () {
    app(LoaderSwitchService::class)->switchLoader($this->server, lslEgg('Mystery Server')->id, '1.21.4', false);

    $s = ModManagerSetting::forServer('srv-lsl')->fresh();
    expect([$s->loader, $s->install_path])->toBe([null, null]);
});
