<?php

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\AutoStartService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/** A switch that stops the server itself to wipe files still starts it again afterwards. */

beforeEach(function () {
    $this->ops = new FakePanelServerOps();
    app()->instance(PanelServerOps::class, $this->ops);
    $this->svc = app(AutoStartService::class);
});

$starts = fn (FakePanelServerOps $ops) => array_values(array_filter($ops->log, fn ($e) => $e === ['power', 'srv-as', 'start']));

it('starts a server the switch stopped, though it reads offline when the reinstall begins', function () use ($starts) {
    $this->svc->stoppedForSwitch('srv-as');
    $this->ops->states['srv-as'] = ['power' => 'offline', 'install' => null];

    $this->svc->remember('srv-as');
    expect($this->svc->resume('srv-as', 'reinstall'))->toBeTrue()
        ->and($starts($this->ops))->toHaveCount(1);
});

it('leaves a server that was off and not stopped by a switch', function () use ($starts) {
    $this->ops->states['srv-as'] = ['power' => 'offline', 'install' => null];

    $this->svc->remember('srv-as');
    expect($this->svc->resume('srv-as', 'reinstall'))->toBeFalse()
        ->and($starts($this->ops))->toBe([]);
});

it('uses the switch mark once, and a failed install drops it', function () use ($starts) {
    $this->ops->states['srv-as'] = ['power' => 'offline', 'install' => null];
    $this->svc->stoppedForSwitch('srv-as');
    $this->svc->remember('srv-as');
    $this->svc->resume('srv-as', 'reinstall');

    $this->svc->remember('srv-as');   // a later, unrelated reinstall
    expect($this->svc->resume('srv-as', 'reinstall'))->toBeFalse();

    $this->svc->stoppedForSwitch('srv-as');
    $this->svc->forget('srv-as');
    $this->svc->remember('srv-as');
    expect($this->svc->resume('srv-as', 'reinstall'))->toBeFalse()
        ->and($starts($this->ops))->toHaveCount(1);
});

