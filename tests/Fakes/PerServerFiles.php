<?php

namespace ServerSuite\Tests\Fakes;

use ServerSuite\Contracts\DaemonFileClient;

/** A FakeFiles per server uuid, for features that touch several servers (#30). */
class PerServerFiles implements DaemonFileClient
{
    /** @var array<string, FakeFiles> */
    public array $servers = [];

    public function for(string $uuid): FakeFiles
    {
        return $this->servers[$uuid] ??= new FakeFiles();
    }

    public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
    {
    }

    public function listDirectory(string $serverUuid, string $directory): array
    {
        return $this->for($serverUuid)->listDirectory($serverUuid, $directory);
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        $this->for($serverUuid)->deleteFiles($serverUuid, $directory, $filenames);
    }

    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
    {
        return $this->for($serverUuid)->readFile($serverUuid, $path, $maxBytes);
    }

    public function writeFile(string $serverUuid, string $path, string $content): void
    {
        $this->for($serverUuid)->writeFile($serverUuid, $path, $content);
    }

    public function tailFile(string $serverUuid, string $path, int $bytes): ?array
    {
        return $this->for($serverUuid)->tailFile($serverUuid, $path, $bytes);
    }

    public function rename(string $serverUuid, string $root, string $from, string $to): void
    {
        $this->for($serverUuid)->rename($serverUuid, $root, $from, $to);
    }

    public function decompress(string $serverUuid, string $directory, string $file): void
    {
    }

    public function makeDirectory(string $serverUuid, string $path): void
    {
    }

    public function chmod(string $serverUuid, string $directory, array $modes): void
    {
    }
}
