<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\Server;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Jobs\SyncAfterInstallJob;
use ServerSuite\Services\PostSwitchService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * After a reinstall the egg's startup must find a jar. Seen on the test
 * panel (2026-10-23): a switch to Forge on Minecraft 26.3 installed
 * forge-26.3-66.1.0-shim.jar and no unix_args.txt; the egg's rename to
 * server.jar failed, so "Unable to access jarfile server.jar".
 */

beforeEach(function () {
    panelTables();
    $egg = Pterodactyl\Models\Egg::query()->create(['name' => 'Forge Minecraft']);
    $this->jarVar = Pterodactyl\Models\EggVariable::query()->create(['egg_id' => $egg->id, 'env_variable' => 'SERVER_JARFILE', 'default_value' => 'server.jar']);
    $this->server = Server::query()->create(['uuid' => 'srv-p', 'egg_id' => $egg->id]);

    $this->wings = new FakeWings();
    app()->instance(DaemonFileClient::class, $this->wings);
    $this->ops = Mockery::mock(FakePanelServerOps::class)->makePartial();
    app()->instance(PanelServerOps::class, $this->ops);
});

it('points SERVER_JARFILE at the Forge shim jar when server.jar is missing', function () {
    $this->wings->put('/forge-26.3-66.1.0-shim.jar', 'shim');
    $this->wings->put('/installer.jar.log', 'log');
    $this->wings->put('/libraries/net/minecraftforge/forge/26.3-66.1.0/forge-26.3-66.1.0-server.jar', 'x');
    $this->ops->shouldReceive('setServerVariables')->once()->with('srv-p', ['SERVER_JARFILE' => 'forge-26.3-66.1.0-shim.jar']);

    $notes = app(PostSwitchService::class)->finish('srv-p');

    expect($notes[0]['body'])->toBe('SERVER_JARFILE server.jar -> forge-26.3-66.1.0-shim.jar');
});

it('leaves a server alone when its jar is there, or it starts from unix_args.txt', function () {
    $this->ops->shouldNotReceive('setServerVariables');

    $this->wings->put('/server.jar', 'paper');
    $this->wings->put('/forge-1.20.1-shim.jar', 'stray');
    expect(app(PostSwitchService::class)->finish('srv-p'))->toBe([]);

    unset($this->wings->files['/server.jar']);
    $this->wings->put('/unix_args.txt', '-p libraries');
    expect(app(PostSwitchService::class)->finish('srv-p'))->toBe([]);
});

it('follows the value set on the server, not the egg default', function () {
    DB::table('server_variables')->insert(['server_id' => $this->server->id, 'variable_id' => $this->jarVar->id, 'variable_value' => 'custom.jar']);
    $this->wings->put('/server.jar', 'jar');
    $this->ops->shouldReceive('setServerVariables')->once()->with('srv-p', ['SERVER_JARFILE' => 'server.jar']);

    app(PostSwitchService::class)->finish('srv-p');
});

it('runs after every install or reinstall, however it was started', function () {
    Queue::fake();
    $this->server->forceFill(['status' => 'installing'])->save();
    Queue::assertNothingPushed();
    $this->server->forceFill(['status' => null])->save();
    Queue::assertPushed(SyncAfterInstallJob::class, fn ($j) => $j->serverUuid === 'srv-p');

    Queue::fake();
    $this->server->forceFill(['status' => 'install_failed'])->save();
    Queue::assertNothingPushed();
});

it('replaces the stub jar the stock Sponge egg downloads with the universal jar', function () {
    $sv = Pterodactyl\Models\EggVariable::query()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'SPONGE_VERSION', 'default_value' => '1.21.8-16.0.0']);
    $this->wings->put('/server.jar', str_repeat('x', 54273));   // spongevanilla-1.21.8-16.0.0.jar: no Main-Class
    $url = 'https://repo.spongepowered.org/repository/maven-releases/org/spongepowered/spongevanilla/1.21.8-16.0.0/spongevanilla-1.21.8-16.0.0-universal.jar';
    $this->wings->remote[$url] = 'universal jar';
    $this->ops->shouldNotReceive('setServerVariables');

    $notes = app(PostSwitchService::class)->finish('srv-p');

    expect($this->wings->files['/server.jar'])->toBe('universal jar')
        ->and($notes[0]['title'])->toBe('Sponge jar replaced');

    // A real jar (or the owner's own) is left alone, and other eggs are untouched.
    $this->wings->put('/server.jar', str_repeat('x', 2 * 1048576));
    expect(app(PostSwitchService::class)->finish('srv-p'))->toBe([]);
    $sv->delete();
    $this->wings->put('/server.jar', 'small paper jar');
    expect(app(PostSwitchService::class)->finish('srv-p'))->toBe([]);
});
