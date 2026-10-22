<?php

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\PropertiesService;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #20: server.properties as a form; restart, or a world reset after a seed change. */

const PROPS = "#Minecraft server properties\n#Mon Oct 20 12:00:00 BST 2026\npvp=true\nmax-players=20\nlevel-seed=\ndifficulty=easy\n";

beforeEach(function () {
    $this->files = new FakeFiles();
    $this->files->put('/server.properties', PROPS);
    $this->power = 'running';
    $this->signals = [];
    $this->wiped = [];
    $ops = Mockery::mock(PanelServerOps::class);
    $ops->shouldReceive('runtimeState')->andReturnUsing(fn () => ['power' => $this->power, 'install' => null]);
    $ops->shouldReceive('power')->andReturnUsing(function ($uuid, $signal) { $this->signals[] = $signal; });
    $delivery = Mockery::mock(FileDeliveryService::class);
    $delivery->shouldReceive('wipeForNuclear')->andReturnUsing(function ($uuid, $mode) { $this->wiped[] = $mode; return []; });
    $this->svc = new PropertiesService($this->files, $ops, $delivery);
});

function propIds(array $open): array
{
    return array_column($open['fields'], 'id', 'label');
}

it('opens the file as a form with the power state', function () {
    $o = $this->svc->open('s');
    $f = array_column($o['fields'], null, 'label');

    expect($o['found'])->toBeTrue()->and($o['power'])->toBe('running')->and($o['seed'])->toBe('')
        ->and($f['pvp']['control'])->toBe('toggle')
        ->and($f['difficulty']['choices'])->toBe(['peaceful', 'easy', 'normal', 'hard']);
});

it('saves in place and can restart', function () {
    $o = $this->svc->open('s');
    $ids = propIds($o);

    $r = $this->svc->save('s', $o['hash'], [$ids['pvp'] => false, $ids['max-players'] => '50'], 'restart');

    expect($r)->toBe(['changed' => ['pvp', 'max-players'], 'seed_changed' => false, 'status' => 'restarting'])
        ->and($this->files->files['/server.properties'])->toBe(str_replace(['pvp=true', 'max-players=20'], ['pvp=false', 'max-players=50'], PROPS))
        ->and($this->signals)->toBe(['restart']);
});

it('resets the world only after a seed change, and only once the server is offline', function () {
    $o = $this->svc->open('s');
    $ids = propIds($o);

    expect(fn () => $this->svc->save('s', $o['hash'], [$ids['pvp'] => false], 'reset_world'))->toThrow(InvalidArgumentException::class, 'seed changes');

    $r = $this->svc->save('s', $o['hash'], [$ids['level-seed'] => '12345'], 'reset_world');
    expect($r['status'])->toBe('stopping')->and($r['seed_changed'])->toBeTrue()->and($this->signals)->toBe(['stop']);

    expect($this->svc->resetWorldStep('s'))->toBe(['status' => 'stopping'])->and($this->wiped)->toBe([], 'still running: nothing deleted');

    $this->power = 'offline';
    expect($this->svc->resetWorldStep('s'))->toBe(['status' => 'done'])
        ->and($this->wiped)->toBe(['keep_plugins'])->and($this->signals)->toBe(['stop', 'start']);
});

it('refuses a stale file and reports a missing one', function () {
    $o = $this->svc->open('s');
    $this->files->put('/server.properties', PROPS . "motd=x\n");

    expect(fn () => $this->svc->save('s', $o['hash'], [], 'none'))->toThrow(InvalidArgumentException::class, 'changed since you opened it');

    unset($this->files->files['/server.properties']);
    expect($this->svc->open('s')['found'])->toBeFalse();
});
