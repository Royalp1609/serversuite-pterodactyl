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

        $params = [
            'directory'  => $directory,
            'foreground' => $foreground,
        ];

        if ($filename !== null) {
            $params['filename'] = $filename;
        }

        try {
            // Pterodactyl's pull signature: pull(string $url, ?string $directory, array $params = [])
            // We pass $directory both positionally and in params for cross-version safety;
            // Wings reads the JSON body keys (url, directory, filename, foreground).
            $repo->pull($url, $directory, $params);
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
