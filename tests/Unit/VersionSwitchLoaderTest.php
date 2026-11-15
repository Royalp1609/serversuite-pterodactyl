<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\LoaderDetectionService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\Data\LoaderDetectionResult;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/** #123: the Paper version switch refuses servers that don't run Paper. */

function vslServer(): object
{
    return (object) ['uuid' => 'srv-vsl', 'skip_scripts' => false, 'image' => 'ghcr.io/pterodactyl/yolks:java_21',
        'egg' => (object) ['docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]];
}

beforeEach(function () {
    $this->ops = new FakePanelServerOps();
    $this->ops->states['srv-vsl'] = ['power' => 'offline', 'install' => null];
    app()->instance(PanelServerOps::class, $this->ops);
    app()->instance(DaemonFileClient::class, Mockery::mock(DaemonFileClient::class)->shouldIgnoreMissing());

    $this->loader = 'fabric';
    $detect = Mockery::mock(LoaderDetectionService::class)->shouldIgnoreMissing();
    foreach (['detectDetailed', 'eggLoader'] as $m) {
        $detect->shouldReceive($m)->andReturnUsing(fn () => new LoaderDetectionResult($this->loader, 90, 'egg', '1.21.1'));
    }
    $detect->shouldReceive('detect')->andReturnUsing(fn () => $this->loader);
    app()->instance(LoaderDetectionService::class, $detect);

    // Paper has builds for 1.21.4.
    Http::fake(['*' => Http::response(['builds' => [['id' => 100, 'channel' => 'STABLE',
        'downloads' => ['server:default' => ['url' => 'https://fill.test/paper-100.jar', 'name' => 'paper-100.jar']]]]])]);
});

it('blocks the switch on a Fabric server and on an unknown loader', function () {
    $pf = app(ServerVersionService::class)->preflight(vslServer(), '1.21.4');
    expect($pf['can_proceed'])->toBeFalse()
        ->and(implode(' ', $pf['blockers']))->toContain('this server runs Fabric');

    $this->loader = null;
    $pf = app(ServerVersionService::class)->preflight(vslServer(), '1.21.4');
    expect(implode(' ', $pf['blockers']))->toContain("Can't tell which loader");
});

it('writes nothing and reinstalls nothing when called directly on a Fabric server', function () {
    expect(fn () => app(VersionSwitchService::class)->apply(vslServer(), '1.21.4', null, false))
        ->toThrow(RuntimeException::class, 'this server runs Fabric');

    expect(array_filter($this->ops->log, fn ($e) => in_array($e[0], ['set_vars', 'set_image', 'reinstall', 'power'], true)))->toBe([]);
});

it('still lets a Paper server through', function () {
    $this->loader = 'paper';
    $pf = app(ServerVersionService::class)->preflight(vslServer(), '1.21.4');

    expect(implode(' ', $pf['blockers']))->not->toContain('Paper servers');
});

it('deletes nothing when the egg refuses a value (#141 items 34, 70)', function () {
    $this->loader = 'paper';
    $ops = new class extends FakePanelServerOps {
        public function setServerVariables(string $serverUuid, array $env): void
        {
            throw new InvalidArgumentException('The egg refuses BUILD_NUMBER');
        }
    };
    $ops->states['srv-vsl'] = ['power' => 'offline', 'install' => null];
    app()->instance(PanelServerOps::class, $ops);
    $files = Mockery::mock(\ServerSuite\Services\FileDeliveryService::class);
    $files->shouldNotReceive('deletePluginJars');
    $files->shouldNotReceive('wipeForNuclear');
    app()->instance(\ServerSuite\Services\FileDeliveryService::class, $files);

    expect(fn () => app(VersionSwitchService::class)->apply(vslServer(), '1.21.4', null, false, true))
        ->toThrow(InvalidArgumentException::class, 'refuses');
    expect(array_filter($ops->log, fn ($e) => $e[0] === 'reinstall'))->toBe([]);
});
