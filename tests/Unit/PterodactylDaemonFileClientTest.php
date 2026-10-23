<?php

use ServerSuite\Daemon\PterodactylDaemonFileClient;

/**
 * DaemonFileRepository::getDirectory() hands back Wings' own JSON
 * (file / directory / modified). Reading is_file from it once made every
 * folder a "file": modpack overrides replaced /mods and /config wholesale
 * and folders were chmod'ed 644 (seen on the test panel, 2026-10-23).
 */
function clientListing(array $raw): array
{
    $client = new class ($raw) extends PterodactylDaemonFileClient {
        public function __construct(private array $raw) {}

        protected function repoForServer(string $serverUuid): object
        {
            return new class ($this->raw) {
                public function __construct(private array $raw) {}

                public function getDirectory(string $path): array
                {
                    return $this->raw;
                }
            };
        }
    };

    return $client->listDirectory('srv', '/');
}

it("reads Wings' own listing: folders are not files, and file times are kept", function () {
    $out = clientListing([
        ['name' => 'mods', 'created' => '2026-10-23T20:47:00Z', 'modified' => '2026-10-23T20:47:10Z', 'mode' => 'drwxr-xr-x', 'mode_bits' => '755', 'size' => 4096, 'directory' => true, 'file' => false, 'symlink' => false, 'mime' => 'inode/directory'],
        ['name' => 'server.jar', 'created' => '2026-10-23T20:45:00Z', 'modified' => '2026-10-23T20:45:01Z', 'mode' => '-rw-r--r--', 'mode_bits' => '644', 'size' => 1234, 'directory' => false, 'file' => true, 'symlink' => false, 'mime' => 'application/jar'],
    ]);

    expect($out)->toBe([
        ['name' => 'mods', 'size' => 4096, 'is_file' => false, 'modified_at' => '2026-10-23T20:47:10Z'],
        ['name' => 'server.jar', 'size' => 1234, 'is_file' => true, 'modified_at' => '2026-10-23T20:45:01Z'],
    ]);
});

it("still reads the client API's shape", function () {
    $out = clientListing(['data' => [
        ['attributes' => ['name' => 'config', 'size' => 0, 'is_file' => false, 'modified_at' => 'x']],
        ['attributes' => ['name' => 'a.jar', 'size' => 3, 'is_file' => true, 'modified_at' => 'y']],
    ]]);

    expect(array_column($out, 'is_file', 'name'))->toBe(['config' => false, 'a.jar' => true]);
});
