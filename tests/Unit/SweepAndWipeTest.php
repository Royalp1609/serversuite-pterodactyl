<?php

use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Exceptions\WipeFailedException;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Tests\Fakes\FakeFiles;

/**
 * #135: removing a mod must not take server files with it.
 * #136: a wipe that fails must throw, not report success.
 */
class WipeFakeFiles extends FakeFiles
{
    public bool $failList = false;
    public bool $failDelete = false;
    public bool $ignoreDelete = false;   // Wings says OK but nothing goes
    public array $deleted = [];

    public function listDirectory(string $serverUuid, string $directory): array
    {
        if ($this->failList) {
            throw DeliveryException::daemonError('simulated list failure');
        }
        if (rtrim('/' . ltrim($directory, '/'), '/') === '' && $this->files === []) {
            return [];
        }

        return parent::listDirectory($serverUuid, $directory);
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        if ($this->failDelete) {
            throw DeliveryException::daemonError('simulated delete failure');
        }
        $dir = rtrim('/' . ltrim($directory, '/'), '/');
        foreach ($filenames as $f) {
            $this->deleted[] = ltrim($dir . '/' . $f, '/');
            if ($this->ignoreDelete) {
                continue;
            }
            foreach (array_keys($this->files) as $path) {
                if ($path === $dir . '/' . $f || str_starts_with($path, $dir . '/' . $f . '/')) {
                    unset($this->files[$path]);
                }
            }
        }
    }
}

function wipeFake(array $paths): WipeFakeFiles
{
    $f = new WipeFakeFiles();
    foreach ($paths as $path => $content) {
        $f->put(is_int($path) ? $content : $path, is_int($path) ? 'x' : $content);
    }

    return $f;
}

it('never deletes files in the server root when a mod shares their name (#135)', function () {
    $f = wipeFake([
        'purpur.yml', 'pufferfish.yml', 'velocity.toml', 'allowlist.json', 'permissions.json',
        'server.properties' => "level-name=survival\n",
        'survival/level.dat', 'purpur/data.yml', 'plugins/Purpur/config.yml', 'config/purpur.toml',
    ]);
    $svc = new FileDeliveryService($f);

    $removed = $svc->sweepConfigs('s', 'Purpur');

    expect($removed)->toEqualCanonicalizing(['plugins/Purpur', 'config/purpur.toml', 'purpur'])
        ->and(array_keys($f->files))->toContain('/purpur.yml', '/pufferfish.yml', '/velocity.toml');
    foreach (['Velocity', 'Allowlist', 'Permissions', 'Pufferfish'] as $mod) {
        $svc->sweepConfigs('s', $mod);
    }
    expect(array_keys($f->files))->toContain('/velocity.toml', '/allowlist.json', '/permissions.json', '/pufferfish.yml');
});

it('does not sweep the custom world folder (#135)', function () {
    $f = wipeFake(['server.properties' => "level-name=Survival\n", 'Survival/level.dat', 'Survival_nether/x']);

    expect((new FileDeliveryService($f))->sweepConfigs('s', 'Survival'))->toBe([])
        ->and(array_keys($f->files))->toContain('/Survival/level.dat', '/Survival_nether/x');
});

it('still sweeps a mod config folder in the root', function () {
    $f = wipeFake(['journeymap/data/x', 'journeymap.txt', 'server.properties' => "level-name=world\n"]);

    expect((new FileDeliveryService($f))->sweepConfigs('s', 'JourneyMap'))->toBe(['journeymap'])
        ->and(array_keys($f->files))->toContain('/journeymap.txt');
});

it('wipes the custom world for keep_plugins and leaves the rest', function () {
    $f = wipeFake(['server.properties' => "level-name=survival\n", 'survival/a', 'survival_nether/b', 'world/c', 'plugins/x.jar']);

    expect((new FileDeliveryService($f))->wipeForNuclear('s', 'keep_plugins'))->toEqualCanonicalizing(['survival', 'survival_nether'])
        ->and(array_keys($f->files))->toEqualCanonicalizing(['/server.properties', '/world/c', '/plugins/x.jar']);
});

it('wipes everything but eula.txt for clean_slate', function () {
    $f = wipeFake(['eula.txt', 'server.properties', 'world/a', 'plugins/x.jar']);

    (new FileDeliveryService($f))->wipeForNuclear('s', 'clean_slate');

    expect(array_keys($f->files))->toBe(['/eula.txt']);
});

it('throws when the wipe cannot list the files (#136)', function () {
    $f = wipeFake(['world/a']);
    $f->failList = true;

    expect(fn () => (new FileDeliveryService($f))->wipeForNuclear('s', 'clean_slate'))
        ->toThrow(WipeFailedException::class, 'nothing was wiped');
});

it('throws when Wings refuses the delete (#136)', function () {
    $f = wipeFake(['world/a']);
    $f->failDelete = true;

    expect(fn () => (new FileDeliveryService($f))->wipeForNuclear('s', 'keep_plugins'))
        ->toThrow(WipeFailedException::class, "Couldn't delete");
});

it('throws when the delete reports success but files are still there (#136)', function () {
    $f = wipeFake(['eula.txt', 'world/a', 'server.properties']);
    $f->ignoreDelete = true;

    expect(fn () => (new FileDeliveryService($f))->wipeForNuclear('s', 'clean_slate'))
        ->toThrow(WipeFailedException::class, 'left files behind');
});

it('refuses a world reset when level-name is a path', function () {
    $f = wipeFake(['server.properties' => "level-name=worlds/survival\n", 'worlds/survival/a']);

    expect(fn () => (new FileDeliveryService($f))->wipeForNuclear('s', 'keep_plugins'))
        ->toThrow(WipeFailedException::class, 'is a path')
        ->and($f->deleted)->toBe([]);
});

it('returns nothing and does not throw when there is no world to wipe', function () {
    $f = wipeFake(['server.properties' => "level-name=world\n", 'plugins/x.jar']);

    expect((new FileDeliveryService($f))->wipeForNuclear('s', 'keep_plugins'))->toBe([]);
});
