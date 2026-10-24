<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\Server;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Jobs\SyncAfterInstallJob;
use ServerSuite\Jobs\SyncAfterRestoreJob;
use ServerSuite\Models\Modpack;
use ServerSuite\Services\PostSwitchService;
use ServerSuite\Services\RestoreSyncService;
use ServerSuite\Tests\Fakes\FakePanelServerOps;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #69: after a reinstall or a backup restore the server is started again if
 * it was running before, however the reinstall or restore was started. The
 * hook reads the power state when the status changes; the jobs start it.
 */

beforeEach(function () {
    panelTables();
    Cache::flush();
    Queue::fake();   // the jobs are run by hand below
    $this->server = Server::query()->create(['uuid' => 'srv-a']);
    app()->instance(DaemonFileClient::class, new FakeWings());
    $this->ops = new FakePanelServerOps();
    $this->ops->states['srv-a'] = ['power' => 'running', 'install' => null];
    app()->instance(PanelServerOps::class, $this->ops);
});

function asStarts(object $t): array
{
    return array_values(array_filter($t->ops->log, fn ($l) => $l[0] === 'power' && $l[2] === 'start'));
}

/** The panel reinstalls: status installing (Wings then stops it), then done. */
function asReinstall(object $t, ?string $end = null): void
{
    $t->server->forceFill(['status' => 'installing'])->save();
    $t->ops->states['srv-a']['power'] = 'offline';
    $t->server->forceFill(['status' => $end])->save();
    if ($end === null) {
        (new SyncAfterInstallJob('srv-a'))->handle(app(PostSwitchService::class));
    }
}

it('starts the server again after a reinstall if it was running', function () {
    asReinstall($this);

    expect(asStarts($this))->toHaveCount(1);
});

it('leaves a server that was off stopped', function () {
    $this->ops->states['srv-a']['power'] = 'offline';
    asReinstall($this);

    expect(asStarts($this))->toBe([]);
});

it('starts nothing after a failed install, now or on the next reinstall', function () {
    asReinstall($this, 'install_failed');
    $this->server->forceFill(['status' => null])->save();
    asReinstall($this);

    expect(asStarts($this))->toBe([]);
});

it("doesn't start it twice when something already did", function () {
    $this->server->forceFill(['status' => 'installing'])->save();
    $this->ops->states['srv-a']['power'] = 'starting';   // the Versions tab starts its own switch
    $this->server->forceFill(['status' => null])->save();
    (new SyncAfterInstallJob('srv-a'))->handle(app(PostSwitchService::class));

    expect(asStarts($this))->toBe([]);
});

it('never during a modpack install', function () {
    Modpack::query()->create(['server_uuid' => 'srv-a', 'status' => Modpack::STATUS_INSTALLING, 'phase' => 'switching']);
    asReinstall($this);

    expect(asStarts($this))->toBe([]);
});

it('starts it again after a restore, but not after a failed one', function () {
    $this->server->forceFill(['status' => 'restoring_backup'])->save();
    $this->ops->states['srv-a']['power'] = 'offline';
    $this->server->forceFill(['status' => null])->save();
    (new SyncAfterRestoreJob('srv-a'))->handle(app(RestoreSyncService::class));
    expect(asStarts($this))->toHaveCount(1);

    $this->ops->log = [];
    $this->ops->states['srv-a']['power'] = 'running';
    $this->server->forceFill(['status' => 'restoring_backup'])->save();
    $this->ops->states['srv-a']['power'] = 'offline';
    $this->server->forceFill(['status' => null])->save();
    $sync = Mockery::mock(RestoreSyncService::class)->makePartial();
    $sync->shouldReceive('completed')->andReturnUsing(function () use ($sync) {
        $sync->lastRestoreFailed = true;

        return null;
    });
    (new SyncAfterRestoreJob('srv-a'))->handle($sync);
    expect(asStarts($this))->toBe([]);
});
