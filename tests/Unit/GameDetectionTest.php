<?php

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\ModpackException;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\GameDetectionService;
use ServerSuite\Services\LoaderSwitchService;
use ServerSuite\Services\ModpackService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\GameGuard;
use ServerSuite\Support\PanelPermissions;

/**
 * #77/#78: which game a server runs, which endpoints apply to it, and
 * Minecraft-only actions refused on other games before anything is touched.
 */

/** A server shaped like Pterodactyl's: egg (with nest), variables as env_variable + server_value/default_value. */
function gameServer(string $uuid, array $egg, array $variables = [], int $eggId = 0): object
{
    return (object) [
        'uuid'      => $uuid,
        'egg_id'    => $eggId,
        'egg'       => (object) array_merge(['uuid' => null, 'name' => '', 'docker_images' => [], 'startup' => '', 'script_install' => '', 'nest' => null], $egg),
        'startup'   => $egg['startup'] ?? '',
        'image'     => null,
        'variables' => collect($variables)->map(fn ($v, $k) => (object) ['env_variable' => $k, 'server_value' => null, 'default_value' => $v])->values(),
    ];
}

function rustServer(string $uuid = 'srv-rust'): object
{
    return gameServer($uuid, [
        'name' => 'Rust', 'docker_images' => ['Rust' => 'ghcr.io/pelican-eggs/games:rust'],
        'startup' => './RustDedicated -batchmode', 'nest' => (object) ['name' => 'Games Steamcmd'],
        'script_install' => 'if [ "${FRAMEWORK}" == "vanilla" ]; then echo vanilla; fi',
    ], ['SRCDS_APPID' => '258550', 'HOSTNAME' => 'Old egg name', 'FRAMEWORK' => 'carbon'], 14);
}

function valheimServer(): object
{
    return gameServer('srv-valheim', [
        'name' => 'Valheim BepINex', 'docker_images' => ['ghcr.io/parkervcp/games:valheim' => 'ghcr.io/parkervcp/games:valheim'],
        'startup' => './valheim_server.x86_64 -nographics', 'nest' => (object) ['name' => 'Games Steamcmd'],
    ], ['SRCDS_APPID' => '896660'], 22);
}

function teamspeakServer(): object
{
    return gameServer('srv-ts3', [
        'name' => 'Teamspeak3 Server', 'docker_images' => ['ghcr.io/pterodactyl/yolks:debian' => 'ghcr.io/pterodactyl/yolks:debian'],
        'startup' => './ts3server default_voice_port={{SERVER_PORT}}', 'nest' => (object) ['name' => 'Voice Servers'],
    ], [], 12);
}

function paperServer(): object
{
    return gameServer('srv-paper', [
        'name' => 'Paper', 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21'],
        'startup' => 'java -jar {{SERVER_JARFILE}}', 'nest' => (object) ['name' => 'Minecraft'],
    ], ['MINECRAFT_VERSION' => 'latest'], 1);
}

beforeEach(fn () => GameDetectionService::flush());
afterEach(fn () => GameDetectionService::flush());

it('recognises Minecraft, Rust, Valheim and unknown games', function () {
    $d = app(GameDetectionService::class);

    expect($d->for(paperServer())->key)->toBe('minecraft')
        ->and($d->for(rustServer())->key)->toBe('rust')
        ->and($d->for(rustServer())->signal)->toBe('steam_app_id')
        ->and($d->for(rustServer())->modsLabel)->toBe('Plugins')
        ->and($d->for(valheimServer())->key)->toBe('valheim')
        ->and($d->for(teamspeakServer())->key)->toBe('unknown');
});

it('treats a Minecraft nest as Minecraft even when the egg names no loader', function () {
    $server = gameServer('srv-mc', ['name' => 'Custom pack', 'nest' => (object) ['name' => 'Minecraft']]);

    expect(app(GameDetectionService::class)->for($server))->key->toBe('minecraft')->signal->toBe('nest');
});

it('treats a server with a stored loader as Minecraft', function () {
    ModManagerSetting::forServer('srv-bare')->forceFill(['loader' => 'fabric'])->save();

    expect(app(GameDetectionService::class)->for((object) ['uuid' => 'srv-bare'])->signal)->toBe('stored_loader')
        ->and(app(GameDetectionService::class)->for((object) ['uuid' => 'srv-none'])->key)->toBe('unknown');
});

it('gives Minecraft every feature and other games the neutral ones', function () {
    $d = app(GameDetectionService::class);

    expect($d->for(paperServer())->supports('versions'))->toBeTrue()
        ->and($d->for(rustServer())->features)->toBe(['dashboard', 'backups', 'notifications', 'subdomains', 'configs', 'errors', 'players'])
        ->and($d->for(rustServer())->configRoots)->toBe(['/oxide/config', '/oxide/data', '/carbon/configs', '/carbon/data', '/server/rust/cfg'])
        ->and($d->for(valheimServer())->configRoots)->toBe(['/BepInEx/config'])
        ->and($d->for(teamspeakServer())->supports('mods'))->toBeFalse();
});

it('reads egg variables through the per-game aliases', function () {
    $d = app(GameDetectionService::class);

    expect($d->variable(rustServer(), 'hostname'))->toBe('Old egg name')
        ->and($d->variable(rustServer(), 'framework'))->toBe('carbon')
        ->and($d->variable(paperServer(), 'hostname'))->toBeNull();
});

it('lets an admin set and clear an egg\'s game', function () {
    $d = app(GameDetectionService::class);

    GameDetectionService::setOverride(12, 'minecraft');
    expect($d->for(teamspeakServer()))->key->toBe('minecraft')->signal->toBe('admin');

    GameDetectionService::setOverride(12, 'unknown');
    GameDetectionService::setOverride(1, 'valheim');
    expect($d->for(paperServer())->key)->toBe('valheim')
        ->and($d->detect(paperServer(), false)->key)->toBe('minecraft');

    GameDetectionService::setOverride(1, null);
    expect($d->for(paperServer())->key)->toBe('minecraft')
        ->and(GameDetectionService::overrides())->toBe([12 => 'unknown'])
        ->and(fn () => GameDetectionService::setOverride(1, 'no-such-game'))->toThrow(InvalidArgumentException::class);
});

it('blocks Minecraft-only endpoints on other games and leaves the rest open', function () {
    expect(GameGuard::endpointBlocker(rustServer(), 'loaderSwitch'))->toBe('This only works on Minecraft servers, and this one runs Rust.')
        ->and(GameGuard::endpointBlocker(teamspeakServer(), 'install'))->toBe('This only works on Minecraft servers.')
        ->and(GameGuard::endpointBlocker(rustServer(), 'backups'))->toBeNull()
        ->and(GameGuard::endpointBlocker(rustServer(), 'subdomainsClaim'))->toBeNull()
        ->and(GameGuard::endpointBlocker(paperServer(), 'loaderSwitch'))->toBeNull()
        // Configs: Minecraft through the Mods tab, Rust/Valheim through their Configs tab (#86).
        ->and(GameGuard::endpointBlocker(rustServer(), 'configsSave'))->toBeNull()
        ->and(GameGuard::endpointBlocker(paperServer(), 'configsOpen'))->toBeNull()
        ->and(GameGuard::endpointBlocker(teamspeakServer(), 'configs'))->toBe('This only works on Minecraft servers.');
});

it('maps every gated endpoint to one with permissions', function () {
    foreach (array_keys(GameGuard::ENDPOINT_FEATURES) as $endpoint) {
        expect(PanelPermissions::ENDPOINTS)->toHaveKey($endpoint);
    }
    expect(PanelPermissions::required('game'))->toBe(['member']);
});

it('refuses version and loader switches, resets and modpacks on other games before touching anything', function () {
    $ops = Mockery::mock(PanelServerOps::class);       // any call fails the test
    app()->instance(PanelServerOps::class, $ops);
    app()->instance(DaemonFileClient::class, Mockery::mock(DaemonFileClient::class));

    expect(fn () => app(LoaderSwitchService::class)->switchLoader(rustServer(), 1, '1.21.1', false))
        ->toThrow(RuntimeException::class, 'Cannot switch loader: this only works on a Minecraft server (this one runs Rust). Nothing was changed.')
        ->and(fn () => app(VersionSwitchService::class)->apply(teamspeakServer(), '1.21.1', null, false, true, 'clean_slate'))
        ->toThrow(RuntimeException::class, 'Cannot change version: this only works on a Minecraft server. Nothing was changed.')
        ->and(fn () => app(VersionSwitchService::class)->nuclear(valheimServer(), 'clean_slate'))
        ->toThrow(RuntimeException::class, 'Cannot reset the server: this only works on a Minecraft server (this one runs Valheim).')
        ->and(fn () => app(ModpackService::class)->start(rustServer(), 'PACK', 'v1', 1))
        ->toThrow(ModpackException::class, 'Cannot install a modpack: this only works on a Minecraft server (this one runs Rust).');
});

it('recognises an egg without SRCDS_APPID by its server binary', function () {
    // Pterodactyl's older Rust egg: the app id is only in its install script.
    $old = gameServer('srv-rust-old', ['name' => 'Rust', 'nest' => (object) ['name' => 'Rust'],
        'startup' => './RustDedicated -batchmode +server.port {{SERVER_PORT}}'], ['HOSTNAME' => 'A Rust Server'], 14);

    expect(app(GameDetectionService::class)->for($old))->key->toBe('rust')->signal->toBe('startup')
        ->and(app(GameDetectionService::class)->variable($old, 'hostname'))->toBe('A Rust Server');
});
