<?php

namespace ServerSuite\Tests\Fakes;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Exceptions\DeliveryException;

/** A server's files in memory, with contents (config editor tests). */
class FakeFiles implements DaemonFileClient
{
    /** @var array<string, string> path => content */
    public array $files = [];

    public function put(string $path, string $content): void
    {
        $this->files['/' . ltrim($path, '/')] = $content;
    }

    public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
    {
    }

    public function listDirectory(string $serverUuid, string $directory): array
    {
        $dir = rtrim('/' . ltrim($directory, '/'), '/');
        $out = [];
        foreach (array_keys($this->files) as $path) {
            if (! str_starts_with($path, $dir . '/')) {
                continue;
            }
            $rest = substr($path, strlen($dir) + 1);
            $name = explode('/', $rest)[0];
            $isFile = ! str_contains($rest, '/');
            $out[$name] = ['name' => $name, 'size' => $isFile ? strlen($this->files[$path]) : 0, 'is_file' => $isFile, 'modified_at' => $isFile ? ($this->mtimes[$path] ?? null) : null];
        }
        if ($out === []) {
            throw DeliveryException::daemonError("{$dir} not found");
        }

        return array_values($out);
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        foreach ($filenames as $f) {
            unset($this->files[rtrim($directory, '/') . '/' . $f]);
        }
    }

    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
    {
        $c = $this->files['/' . ltrim($path, '/')] ?? null;

        return ($c === null || strlen($c) > $maxBytes) ? null : $c;
    }

    public function writeFile(string $serverUuid, string $path, string $content): void
    {
        $this->put($path, $content);
    }

    /** @var array<string, string> path => ISO time, for listDirectory */
    public array $mtimes = [];

    public function downloadTo(string $serverUuid, string $path, string $localPath, int $maxBytes): bool
    {
        $c = $this->files['/' . ltrim($path, '/')] ?? null;

        return $c !== null && strlen($c) <= $maxBytes && file_put_contents($localPath, $c) !== false;
    }

    public function tailFile(string $serverUuid, string $path, int $bytes): ?array
    {
        $c = $this->files['/' . ltrim($path, '/')] ?? null;

        return $c === null ? null : ['text' => substr($c, -$bytes), 'size' => strlen($c)];
    }

    public function rename(string $serverUuid, string $root, string $from, string $to): void
    {
        $dir = rtrim('/' . ltrim($root, '/'), '/');
        $this->put("{$dir}/{$to}", $this->files["{$dir}/{$from}"] ?? throw \ServerSuite\Exceptions\DeliveryException::daemonError("{$from} not found"));
        unset($this->files["{$dir}/{$from}"]);
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
