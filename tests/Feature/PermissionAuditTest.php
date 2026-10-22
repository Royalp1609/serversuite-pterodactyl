<?php

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Services\ManifestService;
use ServerSuite\Services\ReconciliationService;
use ServerSuite\Support\RepositoryManager;

/**
 * #36 audit. Adopting a file found on disk: only a bare name of an untracked
 * jar in the mods folder. The stored name decides what removing the mod
 * deletes, so a path could otherwise reach files outside the mods folder.
 */

function auditRecon(array $onDisk): ReconciliationService
{
    $files = Mockery::mock(DaemonFileClient::class);
    $files->shouldReceive('listDirectory')->andReturn(array_map(fn ($n) => ['name' => $n, 'size' => 10, 'is_file' => true, 'modified_at' => null], $onDisk));
    $files->shouldReceive('readFile')->andReturn(null);
    $repos = Mockery::mock(RepositoryManager::class);
    $repos->shouldReceive('driver')->andThrow(new RuntimeException('offline'));

    return new ReconciliationService($files, app(FileDeliveryService::class), $repos, app(ManifestService::class));
}

beforeEach(function () {
    ModManagerSetting::forServer('srv-a')->forceFill(['loader' => 'paper'])->save();
    $this->server = (object) ['uuid' => 'srv-a', 'id' => 1];
});

it('adopts an untracked jar that is in the mods folder', function () {
    $row = auditRecon(['EssentialsX.jar'])->adopt($this->server, 'EssentialsX.jar', 'manual', null, null, 7);

    expect($row->target_filename)->toBe('EssentialsX.jar')->and($row->server_uuid)->toBe('srv-a');
});

it('refuses names that are paths, missing, or already tracked', function () {
    ServerMod::query()->create(['server_uuid' => 'srv-a', 'source' => 'manual', 'source_id' => 'x', 'mod_name' => 'Tracked',
        'installed_version' => '1', 'target_filename' => 'Tracked.jar', 'install_path' => '/plugins']);
    $recon = auditRecon(['EssentialsX.jar', 'Tracked.jar']);

    foreach (['../server.jar', '../../etc/passwd', 'sub/EssentialsX.jar', '..', '', "Essentials\0.jar", 'NotThere.jar', 'Tracked.jar'] as $name) {
        expect(fn () => $recon->adopt($this->server, $name, 'manual', null, null, 7))
            ->toThrow(InvalidArgumentException::class);
    }
    expect(ServerMod::query()->count())->toBe(1);
});

it('knows a bare file name', function () {
    expect(ReconciliationService::isBareFilename('a-b_c 1.2.jar'))->toBeTrue()
        ->and(ReconciliationService::isBareFilename('..\\x.jar'))->toBeFalse()
        ->and(ReconciliationService::isBareFilename('a/..'))->toBeFalse();
});

it('only switches to a loader egg the Versions tab offers', function () {
    $src = file_get_contents(__DIR__ . '/../../extension/app/ModManagerController.php');
    $fn = substr($src, strpos($src, 'public function loaderSwitch('));
    $fn = substr($fn, 0, strpos($fn, "\n    }\n"));

    expect($fn)->toContain('minecraftLoaders()')
        ->and(strpos($fn, 'minecraftLoaders()'))->toBeLessThan(strpos($fn, 'switchLoader('), 'checked before anything changes');
});
