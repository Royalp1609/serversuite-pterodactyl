<?php

use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Services\CrashHelpService;
use ServerSuite\Support\CrashParser;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #25: crash help after a mod change, on Pterodactyl. */

beforeEach(function () {
    $this->files = new FakeFiles();
    $this->svc = new CrashHelpService($this->files);
    $this->server = (object) ['uuid' => 'srv-c'];
    $this->mod = ServerMod::query()->create([
        'server_uuid' => 'srv-c', 'source' => 'modrinth', 'source_id' => 'AANobbMI', 'mod_name' => 'Sodium', 'mod_slug' => 'sodium',
        'installed_version' => '0.4.10', 'target_filename' => 'sodium.jar', 'install_path' => '/mods',
    ]);
    $this->files->put('/mods/sodium.jar', 'jar');
});

it('names the mod behind a crash that followed the last change, with the backup from before it', function () {
    $this->svc->recordChange('srv-c', 'install Sodium Extra', ['uuid' => 'b1', 'name' => 'Pre-mod-change']);
    $this->files->put('/crash-reports/crash-1.txt', "---- Minecraft Crash Report ----\nDescription: Mod loading failed\n\nSuspected Mod: Sodium (sodium), Version: 0.4.10\n");
    $this->files->mtimes['/crash-reports/crash-1.txt'] = now()->addMinute()->toIso8601String();

    $crash = $this->svc->analyze($this->server);

    expect($crash['change']['label'])->toBe('install Sodium Extra')
        ->and($crash['change']['backup'])->toBe(['uuid' => 'b1', 'name' => 'Pre-mod-change'])
        ->and($crash['suspects'][0])->toMatchArray(['id' => 'sodium', 'server_mod_id' => $this->mod->id, 'mod_name' => 'Sodium', 'disabled' => false]);
});

it('reads only the end of a large log', function () {
    $this->svc->recordChange('srv-c', 'update Sodium');
    $this->files->put('/logs/latest.log', str_repeat("[12:00:00] [main/INFO]: filler\n", 400000) . "[12:00:09] [main/ERROR]: Incompatible mods found!\n\t - Mod 'Sodium' (sodium) 0.4.10 requires something\n");
    $this->files->mtimes['/logs/latest.log'] = now()->addMinute()->toIso8601String();

    expect($this->svc->analyze($this->server)['suspects'][0]['id'])->toBe('sodium');
});

it('ignores crashes from before the change, and a dismissed one', function () {
    $this->files->put('/crash-reports/old.txt', "---- Minecraft Crash Report ----\nDescription: old\n");
    $this->files->mtimes['/crash-reports/old.txt'] = now()->subHour()->toIso8601String();
    $this->svc->recordChange('srv-c', 'install X');
    expect($this->svc->analyze($this->server))->toBeNull();

    $this->files->mtimes['/crash-reports/old.txt'] = now()->addMinute()->toIso8601String();
    $this->svc->dismiss('srv-c');
    expect($this->svc->analyze($this->server))->toBeNull();
});

it('disables a mod by renaming its jar, and enables it again', function () {
    $this->svc->disable($this->mod);
    expect($this->files->files)->toHaveKey('/mods/sodium.jar.disabled')->not->toHaveKey('/mods/sodium.jar')
        ->and($this->mod->fresh()->disabled_at)->not->toBeNull();

    $this->svc->enable($this->mod->fresh());
    expect($this->files->files)->toHaveKey('/mods/sodium.jar')->and($this->mod->fresh()->disabled_at)->toBeNull();
});

it('parses the loaders the same way as on Pelican', function () {
    $r = CrashParser::parse("Could not load 'plugins/Broken.jar' in folder 'plugins'\norg.bukkit.plugin.InvalidPluginException");

    expect($r['failed'])->toBeTrue()->and($r['suspects'][0]['file'])->toBe('Broken.jar');
});
