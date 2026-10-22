<?php

namespace ServerSuite\Tests\Fakes;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Exceptions\DeliveryException;

/**
 * In-memory Wings for one server: files are path => bytes, folders are
 * implied by files or created explicitly. pull() serves bytes from $remote
 * (url => bytes), decompress() really unzips, rename() refuses an existing
 * target like Wings does.
 */
class FakeWings implements DaemonFileClient
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    public array $dirs = ['/' => true];

    /** @var array<string, string> url => bytes */
    public array $remote = [];

    /** @var string[] every pulled URL, in order */
    public array $pulled = [];

    /** @var array<string, int> path => permission bits, as unpacked or chmod'ed */
    public array $modes = [];

    /** @var array<string, string> path => ISO modified time (set by put()) */
    public array $mtimes = [];

    public static function norm(string $path): string
    {
        $path = '/' . trim(str_replace('//', '/', $path), '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function join(string $dir, string $name): string
    {
        return self::norm($dir . '/' . $name);
    }

    private function isDir(string $path): bool
    {
        $path = self::norm($path);
        if (isset($this->dirs[$path])) {
            return true;
        }
        $prefix = rtrim($path, '/') . '/';
        foreach (array_merge(array_keys($this->files), array_keys($this->dirs)) as $p) {
            if (str_starts_with($p, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function put(string $path, string $bytes, ?string $modified = null): void
    {
        $this->files[self::norm($path)] = $bytes;
        $this->mtimes[self::norm($path)] = $modified ?? now()->toIso8601String();
    }

    public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
    {
        $this->pulled[] = $url;
        if (! array_key_exists($url, $this->remote)) {
            throw DeliveryException::daemonError("pull failed: 404 for {$url}");
        }
        $this->put($this->join($directory, $filename ?? basename($url)), $this->remote[$url]);
    }

    public function listDirectory(string $serverUuid, string $directory): array
    {
        $dir = self::norm($directory);
        if (! $this->isDir($dir)) {
            throw DeliveryException::daemonError("list-directory failed: {$dir} not found");
        }
        $prefix = $dir === '/' ? '/' : $dir . '/';
        $out = [];
        foreach (array_merge(array_keys($this->files), array_keys($this->dirs)) as $p) {
            if ($p === $dir || ! str_starts_with($p, $prefix)) {
                continue;
            }
            $rest = substr($p, strlen($prefix));
            $name = explode('/', $rest)[0];
            if ($name === '' || isset($out[$name])) {
                continue;
            }
            $isFile = ! str_contains($rest, '/') && isset($this->files[$p]);
            $out[$name] = ['name' => $name, 'size' => $isFile ? strlen($this->files[$p]) : 0, 'is_file' => $isFile, 'modified_at' => $isFile ? ($this->mtimes[$p] ?? null) : null];
        }

        return array_values($out);
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        foreach ($filenames as $name) {
            $target = $this->join($directory, $name);
            if (! isset($this->files[$target]) && ! $this->isDir($target)) {
                throw DeliveryException::daemonError("delete failed: {$target} not found");
            }
            unset($this->files[$target], $this->dirs[$target]);
            foreach ([&$this->files, &$this->dirs] as &$set) {
                foreach (array_keys($set) as $p) {
                    if (str_starts_with($p, $target . '/')) {
                        unset($set[$p]);
                    }
                }
            }
            unset($set);
        }
    }

    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
    {
        $b = $this->files[self::norm($path)] ?? null;

        return ($b === null || strlen($b) > $maxBytes) ? null : $b;
    }

    public function decompress(string $serverUuid, string $directory, string $file): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'fakezip');
        file_put_contents($tmp, $this->files[$this->join($directory, $file)] ?? throw DeliveryException::daemonError('decompress failed: no archive'));
        $zip = new \ZipArchive();
        $zip->open($tmp);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                $this->dirs[$this->join($directory, $name)] = true;
            } else {
                $this->put($path = $this->join($directory, $name), (string) $zip->getFromIndex($i));
                $zip->getExternalAttributesIndex($i, $os, $attr);
                $this->modes[$path] = ($attr >> 16) & 0777;   // Wings keeps the archive's bits
            }
        }
        $zip->close();
        unlink($tmp);
    }

    public function rename(string $serverUuid, string $root, string $from, string $to): void
    {
        $src = $this->join($root, $from);
        $dst = $this->join($root, $to);
        if (isset($this->files[$dst]) || $this->isDir($dst)) {
            throw DeliveryException::daemonError("rename failed: {$dst} exists");
        }
        if (isset($this->files[$src])) {
            $this->files[$dst] = $this->files[$src];
            $this->mtimes[$dst] = $this->mtimes[$src] ?? now()->toIso8601String();
            unset($this->files[$src], $this->mtimes[$src]);
            if (isset($this->modes[$src])) {
                $this->modes[$dst] = $this->modes[$src];
                unset($this->modes[$src]);
            }

            return;
        }
        foreach ([&$this->files, &$this->dirs, &$this->modes] as &$set) {
            foreach (array_keys($set) as $p) {
                if ($p === $src || str_starts_with($p, $src . '/')) {
                    $set[$dst . substr($p, strlen($src))] = $set[$p];
                    unset($set[$p]);
                }
            }
        }
        unset($set);
    }

    public function makeDirectory(string $serverUuid, string $path): void
    {
        $this->dirs[self::norm($path)] = true;
    }

    public function chmod(string $serverUuid, string $directory, array $modes): void
    {
        foreach ($modes as $name => $mode) {
            $this->modes[$this->join($directory, $name)] = octdec($mode);
        }
    }

    public function tailFile(string $serverUuid, string $path, int $bytes): ?array
    {
        $b = $this->files[self::norm($path)] ?? null;

        return $b === null ? null : ['text' => substr($b, -$bytes), 'size' => strlen($b)];
    }

    /** Beyond the contract, like PterodactylDaemonFileClient (used by Properties). */
    public function writeFile(string $serverUuid, string $path, string $content): void
    {
        $this->put($path, $content);
    }
}
