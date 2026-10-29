<?php

use ServerSuite\Services\ConfigFilesService;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #21: the Mods tab's Configs view on Pterodactyl (text and typed form). */

const CFG_YAML = "# Join message\nmotd: Hello\nlimits:\n  homes: 3\n  pvp: true\n";

beforeEach(function () {
    $this->files = new FakeFiles();
    $this->files->put('/plugins/Essentials/config.yml', CFG_YAML);
    $this->files->put('/plugins/Essentials/notes.bin', 'x');
    $this->files->put('/config/sodium.json', '{"a": 1}');
    $this->files->put('/server.jar', 'jar');
    $this->svc = new ConfigFilesService($this->files);
});

it('lists the config roots and only config files', function () {
    $roots = $this->svc->browse('s', null);
    expect(array_column($roots['entries'], 'path'))->toBe(['/plugins/Essentials', '/config/sodium.json']);

    $dir = $this->svc->browse('s', '/plugins/Essentials');
    expect(array_column($dir['entries'], 'name'))->toBe(['config.yml']);
});

it('refuses anything outside the roots', function () {
    foreach (['/server.jar', '/plugins/../server.jar', '/', '/pluginsX/a.yml', "/plugins/a\0.yml"] as $p) {
        expect(fn () => ConfigFilesService::safe($p))->toThrow(InvalidArgumentException::class);
    }
    expect(ConfigFilesService::safe('plugins//Essentials/'))->toBe('/plugins/Essentials');
});

it('opens a file as a form and saves only the edited values', function () {
    $o = $this->svc->open('s', '/plugins/Essentials/config.yml');
    $ids = array_column($o['fields'], 'id', 'label');
    expect($o['reason'])->toBeNull()->and($o['hash'])->toBe(sha1(CFG_YAML));

    $r = $this->svc->save('s', '/plugins/Essentials/config.yml', $o['hash'], [$ids['limits.homes'] => '5', $ids['limits.pvp'] => false], null);

    expect($r)->toBe(['changed' => ['limits.homes', 'limits.pvp'], 'written' => true])
        ->and($this->files->files['/plugins/Essentials/config.yml'])->toBe("# Join message\nmotd: Hello\nlimits:\n  homes: 5\n  pvp: false\n");
});

it('renders form edits as text without saving, and saves text too', function () {
    $o = $this->svc->open('s', '/plugins/Essentials/config.yml');
    $ids = array_column($o['fields'], 'id', 'label');

    expect($this->svc->render('s', '/plugins/Essentials/config.yml', $o['hash'], [$ids['motd'] => 'Hi']))->toBe(str_replace('Hello', 'Hi', CFG_YAML))
        ->and($this->files->files['/plugins/Essentials/config.yml'])->toBe(CFG_YAML);

    $this->svc->save('s', '/plugins/Essentials/config.yml', $o['hash'], null, "motd: Bye\n");
    expect($this->files->files['/plugins/Essentials/config.yml'])->toBe("motd: Bye\n");
});

it('refuses to overwrite a file that changed on disk, and bad values', function () {
    $o = $this->svc->open('s', '/plugins/Essentials/config.yml');
    $ids = array_column($o['fields'], 'id', 'label');

    expect(fn () => $this->svc->save('s', '/plugins/Essentials/config.yml', $o['hash'], [$ids['limits.homes'] => 'lots'], null))
        ->toThrow(ServerSuite\Support\Config\ConfigEditException::class, "isn't a whole number");

    $this->files->put('/plugins/Essentials/config.yml', CFG_YAML . "new: 1\n");
    expect(fn () => $this->svc->save('s', '/plugins/Essentials/config.yml', $o['hash'], null, 'x'))
        ->toThrow(InvalidArgumentException::class, 'since you opened it');
    expect($this->files->files['/plugins/Essentials/config.yml'])->toBe(CFG_YAML . "new: 1\n");
});

it('keeps text files that have no form as text, and caps the size', function () {
    $this->files->put('/config/notes.txt', 'hello');
    $o = $this->svc->open('s', '/config/notes.txt');
    expect($o['fields'])->toBe([])->and($o['reason'])->not->toBeNull();

    $this->files->put('/config/big.yml', str_repeat('a', ConfigFilesService::MAX_BYTES + 1));
    expect(fn () => $this->svc->open('s', '/config/big.yml'))->toThrow(InvalidArgumentException::class, '512 KiB');
});

it('browses and saves a game\'s own folders and nothing else (#86)', function () {
    $roots = ['/BepInEx/config'];
    $bep = "[Logging.Console]\n\n## Enables showing a console for log output.\n# Setting type: Boolean\n# Default value: true\nEnabled = true\n";
    $this->files->put('/BepInEx/config/BepInEx.cfg', $bep);

    expect(array_column($this->svc->browse('s', null, $roots)['entries'], 'path'))->toBe(['/BepInEx/config/BepInEx.cfg'])
        ->and(fn () => $this->svc->open('s', '/plugins/Essentials/config.yml', $roots))->toThrow(InvalidArgumentException::class, 'Only files under /BepInEx/config');

    $o = $this->svc->open('s', '/BepInEx/config/BepInEx.cfg', $roots);
    $ids = array_column($o['fields'], 'id', 'label');
    $this->svc->save('s', '/BepInEx/config/BepInEx.cfg', $o['hash'], [$ids['Logging.Console.Enabled'] => false], null, $roots);

    expect($this->files->files['/BepInEx/config/BepInEx.cfg'])->toBe(str_replace('Enabled = true', 'Enabled = false', $bep));
});
