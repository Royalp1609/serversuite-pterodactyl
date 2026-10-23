<?php

namespace ServerSuite\Daemon;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Exceptions\DeliveryException;

/**
 * Pterodactyl implementation of the daemon file client.
 *
 * Wraps Pterodactyl\Repositories\Wings\DaemonFileRepository, whose verified
 * method signatures (Pterodactyl 1.x) are:
 *
 *   setServer(Server $server): self          (inherited from DaemonRepository)
 *   getDirectory(string $path): array
 *   deleteFiles(?string $root, array $files): ResponseInterface
 *   pull(string $url, ?string $directory, array $params = []): ResponseInterface
 *     (we send the same request through getHttpClient() for a longer timeout)
 *   decompressFile(?string $root, string $file): ResponseInterface
 *   createDirectory(string $name, string $path): ResponseInterface
 *   chmodFiles(?string $root, array $files): ResponseInterface
 *
 * We reference these classes by fully-qualified string and resolve via the
 * container, so this file can exist in the package without hard-importing
 * panel classes (which keeps static analysis and the test suite happy on a
 * machine that doesn't have Pterodactyl installed). If a class is missing,
 * we fail with a clear DeliveryException rather than a fatal error.
 */
class PterodactylDaemonFileClient implements DaemonFileClient
{
    private const REPO_CLASS   = \Pterodactyl\Repositories\Wings\DaemonFileRepository::class;
    private const SERVER_MODEL = \Pterodactyl\Models\Server::class;

    public function pull(
        string $serverUuid,
        string $url,
        string $directory,
        ?string $filename = null,
        bool $foreground = true,
    ): void {
        $repo = $this->repoForServer($serverUuid);

        // Same request as DaemonFileRepository::pull(), with its own timeout:
        // a foreground pull holds the request open until Wings has the whole
        // file, and the panel's default (GUZZLE_TIMEOUT, 15s) cut big files
        // off mid-download (cURL error 28). Modpacks pull whole .mrpacks (#23).
        $timeout = $foreground ? max(30, (int) config('serversuite.delivery.pull_timeout', 600)) : (int) config('pterodactyl.guzzle.timeout', 15);

        try {
            $repo->getHttpClient()->post(sprintf('/api/servers/%s/files/pull', $serverUuid), [
                'json' => array_filter([
                    'url'        => $url,
                    'root'       => $directory,
                    'file_name'  => $filename,
                    'foreground' => $foreground ?: null,
                ], static fn ($v) => $v !== null),
                'timeout' => $timeout,
            ]);
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError($e->getMessage(), $e);
        }
    }

    public function listDirectory(string $serverUuid, string $directory): array
    {
        $repo = $this->repoForServer($serverUuid);

        try {
            $raw = $repo->getDirectory($directory);
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError($e->getMessage(), $e);
        }

        // getDirectory returns the daemon's JSON: { "data": [ { "attributes": {...} } ] }
        // or, depending on version, a bare list. Normalise both shapes.
        $entries = $raw['data'] ?? $raw;

        $out = [];
        foreach ((array) $entries as $entry) {
            $attr = $entry['attributes'] ?? $entry;

            if (! is_array($attr) || ! isset($attr['name'])) {
                continue;
            }

            $out[] = [
                'name'        => (string) $attr['name'],
                'size'        => (int) ($attr['size'] ?? 0),
                'is_file'     => (bool) ($attr['is_file'] ?? true),
                'modified_at' => $attr['modified_at'] ?? null,
            ];
        }

        return $out;
    }

    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
    {
        $repo = $this->repoForServer($serverUuid);

        try {
            $repo->deleteFiles($directory, array_values($filenames));
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError($e->getMessage(), $e);
        }
    }

    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
    {
        try {
            $repo = $this->repoForServer($serverUuid);

            // Pterodactyl's getContent(string $path, ?int $notLargerThan = null)
            // throws FileSizeTooLargeException / 404s for missing files — all of
            // which simply mean "no version evidence here", hence null.
            $content = $repo->getContent($path, $maxBytes);

            return is_string($content) ? $content : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function writeFile(string $serverUuid, string $path, string $content): void
    {
        try {
            $this->repoForServer($serverUuid)->putContent($path, $content);
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError('write failed: ' . $e->getMessage(), $e);
        }
    }

    public function tailFile(string $serverUuid, string $path, int $bytes): ?array
    {
        try {
            $repo = $this->repoForServer($serverUuid);
            $response = $repo->getHttpClient()->get("/api/servers/{$serverUuid}/files/contents", [
                'query' => ['file' => $path], 'stream' => true, 'timeout' => 30,
            ]);
            $body = $response->getBody();
            $tail = '';
            $size = 0;
            while (! $body->eof()) {
                $chunk = $body->read(262144);
                $size += strlen($chunk);
                $tail .= $chunk;
                if (strlen($tail) > 2 * $bytes) {
                    $tail = substr($tail, -$bytes);
                }
            }

            return ['text' => strlen($tail) > $bytes ? substr($tail, -$bytes) : $tail, 'size' => $size];
        } catch (\Throwable) {
            return null;
        }
    }

    public function rename(string $serverUuid, string $root, string $from, string $to): void
    {
        try {
            $this->repoForServer($serverUuid)->renameFiles($root, [['from' => $from, 'to' => $to]]);
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError('rename failed: ' . $e->getMessage(), $e);
        }
    }

    public function decompress(string $serverUuid, string $directory, string $file): void
    {
        try {
            // Pterodactyl's client waits up to 15 minutes for big archives.
            $this->repoForServer($serverUuid)->decompressFile($directory, $file);
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError('decompress failed: ' . $e->getMessage(), $e);
        }
    }

    public function makeDirectory(string $serverUuid, string $path): void
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') {
            return;
        }

        try {
            // Wings creates parents and doesn't mind an existing folder.
            $this->repoForServer($serverUuid)->createDirectory(basename($path), dirname($path));
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError('create-directory failed: ' . $e->getMessage(), $e);
        }
    }

    public function chmod(string $serverUuid, string $directory, array $modes): void
    {
        if ($modes === []) {
            return;
        }

        try {
            $this->repoForServer($serverUuid)->chmodFiles($directory, array_map(
                fn ($name, $mode) => ['file' => (string) $name, 'mode' => (string) $mode],
                array_keys($modes), $modes,
            ));
        } catch (\Throwable $e) {
            throw DeliveryException::daemonError('chmod failed: ' . $e->getMessage(), $e);
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Resolve a DaemonFileRepository bound to the given server.
     */
    protected function repoForServer(string $serverUuid): object
    {
        if (! class_exists(self::REPO_CLASS) || ! class_exists(self::SERVER_MODEL)) {
            throw DeliveryException::daemonError(
                'Pterodactyl daemon classes are not available in this environment.'
            );
        }

        $serverModel = self::SERVER_MODEL;
        $server = $serverModel::query()->where('uuid', $serverUuid)->first();

        if (! $server) {
            throw DeliveryException::daemonError("Server {$serverUuid} not found.");
        }

        /** @var object $repo */
        $repo = app(self::REPO_CLASS);

        // DaemonRepository::setServer() is fluent and required before any call.
        return $repo->setServer($server);
    }
}
