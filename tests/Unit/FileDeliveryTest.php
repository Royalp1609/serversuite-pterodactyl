<?php

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Services\FileDeliveryService;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Support\Data\ModVersion;

/**
 * In-memory fake daemon that simulates Wings: pull() records a file with a
 * size we control, listDirectory() returns it, deleteFiles() removes it.
 */
class FakeDaemonFileClient implements DaemonFileClient
{
    /** @var array<string, array<string, array{size:int}>> */
    public array $files = [];           // [serverUuid][directory/filename] = ['size'=>..]
    public int $pulledSize = 0;          // size to report for the next pulled file
    public bool $failPull = false;
    public array $pullCalls = [];

    public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
    {
        $this->pullCalls[] = compact('serverUuid', 'url', 'directory', 'filename', 'foreground');

        if ($this->failPull) {
            throw DeliveryException::daemonError('simulated pull failure');
        }

        $this->files[$serverUuid][$directory . '/' . $filename] = ['size' => $this->pulledSize];
    }

    public function listDirectory(string $serverUuid, string $directory): array
    {
        $out = [];
        foreach ($this->files[$serverUuid] ?? [] as $path => $meta) {
            [$dir, $name] = [dirname($path), basename($path)];
            if ($dir === $directory) {
                $out[] = ['name' => $name, 'size' => $meta['size'], 'is_file' => true, 'modified_at' => null];
            }
        }
        return $out;
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        foreach ($filenames as $name) {
            unset($this->files[$serverUuid][$directory . '/' . $name]);
        }
    }

    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
    {
        $meta = $this->files[$serverUuid][$path] ?? null;

        if ($meta === null || $meta['size'] > $maxBytes) {
            return null;
        }

        return str_repeat('x', $meta['size']);
    }

    public function writeFile(string $serverUuid, string $path, string $content): void
    {
        $this->files[$serverUuid][$path] = ['size' => strlen($content)];
    }
}

function makeVersion(int $size, string $filename = 'sodium-fabric.jar', string $url = 'https://cdn/sodium.jar'): ModVersion
{
    return new ModVersion(
        versionId: 'v1',
        versionNumber: '0.6.0',
        loaders: ['fabric'],
        gameVersions: ['1.21.1'],
        downloadUrl: $url,
        filename: $filename,
        fileSize: $size,
        changelog: null,
        hashes: ['sha1' => 'abc'],
        channel: 'release',
    );
}

function deliveryService(FakeDaemonFileClient $fake): FileDeliveryService
{
    return new FileDeliveryService($fake);
}

beforeEach(function () {
    // A server whose loader maps /mods
    ModManagerSetting::create([
        'server_uuid' => 'srv-1',
        'loader'      => 'fabric',
        'modrinth_enabled' => true,
        'curseforge_enabled' => false,
        'hangar_enabled' => false,
        'auto_update_checks' => true,
    ]);
});

it('pulls a file to the loader directory and verifies size', function () {
    $fake = new FakeDaemonFileClient();
    $fake->pulledSize = 5000;

    $result = deliveryService($fake)->deliver('srv-1', makeVersion(5000), 'Sodium');

    expect($result['verified'])->toBeTrue()
        ->and($result['directory'])->toBe('/mods')
        ->and($result['filename'])->toBe('sodium-fabric.jar')
        ->and($fake->pullCalls)->toHaveCount(1)
        ->and($fake->pullCalls[0]['directory'])->toBe('/mods');
});

it('refuses a version with no download url', function () {
    $fake = new FakeDaemonFileClient();

    deliveryService($fake)->deliver('srv-1', makeVersion(100, 'x.jar', ''), 'BrokenMod');
})->throws(DeliveryException::class, 'no public download URL');

it('fails verification and cleans up on a size mismatch', function () {
    $fake = new FakeDaemonFileClient();
    $fake->pulledSize = 4096;                 // daemon reports wrong size

    $threw = false;
    try {
        deliveryService($fake)->deliver('srv-1', makeVersion(5000), 'Sodium');
    } catch (DeliveryException $e) {
        $threw = true;
        expect($e->reason)->toBe('verification_failed');
    }

    expect($threw)->toBeTrue()
        // the bad file must have been deleted during cleanup
        ->and($fake->files['srv-1'] ?? [])->toBe([]);
});

it('propagates a daemon pull failure', function () {
    $fake = new FakeDaemonFileClient();
    $fake->failPull = true;

    deliveryService($fake)->deliver('srv-1', makeVersion(5000), 'Sodium');
})->throws(DeliveryException::class);

it('removes a file via the daemon', function () {
    $fake = new FakeDaemonFileClient();
    $fake->files['srv-1']['/mods/old.jar'] = ['size' => 10];

    deliveryService($fake)->remove('srv-1', '/mods', 'old.jar');

    expect($fake->files['srv-1'])->toBe([]);
});
