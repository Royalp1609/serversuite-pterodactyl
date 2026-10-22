<?php

namespace ServerSuite\Contracts;

/**
 * Abstraction over the panel's Wings daemon file API.
 *
 * This is the SINGLE point where the plugin touches panel-internal classes.
 * Pterodactyl and Pelican both expose a Wings file repository, but under
 * different class names — each panel gets its own implementation of this
 * interface, selected by PanelCompatService. Everything else in the plugin
 * depends only on this contract.
 *
 * All methods operate on a server identified by its UUID. Implementations are
 * responsible for resolving that UUID to the panel's Server model and wiring
 * up the daemon connection (node secret, base URL, signing).
 */
interface DaemonFileClient
{
    /**
     * Instruct Wings to download a remote file directly into the server.
     *
     * The daemon fetches $url itself — the file never passes through the panel.
     *
     * @param  string      $serverUuid  Target server UUID.
     * @param  string      $url         Public, directly-downloadable file URL.
     * @param  string      $directory   Server-relative directory, e.g. "/mods".
     * @param  string|null $filename    Desired filename; null lets Wings infer it.
     * @param  bool        $foreground  When true, the daemon completes the download
     *                                  before responding (so we can verify immediately).
     *                                  When false, Wings downloads asynchronously.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException on connection/transfer failure.
     */
    public function pull(
        string $serverUuid,
        string $url,
        string $directory,
        ?string $filename = null,
        bool $foreground = true,
    ): void;

    /**
     * List the contents of a server directory.
     *
     * Used for post-install verification (confirm the file landed at the
     * expected size). Returns a normalised array of entries, each:
     *   ['name' => string, 'size' => int, 'is_file' => bool, 'modified_at' => ?string]
     *
     * @return array<int, array{name: string, size: int, is_file: bool, modified_at: ?string}>
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function listDirectory(string $serverUuid, string $directory): array;

    /**
     * Delete files within a directory on the server.
     *
     * @param  string   $serverUuid
     * @param  string   $directory   Root directory the filenames are relative to.
     * @param  string[] $filenames   Filenames (not full paths) to delete.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function deleteFiles(string $serverUuid, string $directory, array $filenames): void;

    /**
     * Read the contents of a small file on the server, or null if it doesn't
     * exist / is too large / the daemon is unreachable.
     *
     * Intentionally non-throwing: callers use this for best-effort detection
     * (e.g. sourcing the Minecraft version from logs), which must never break
     * the feature it supports.
     *
     * @param  string $serverUuid
     * @param  string $path      Server-relative file path, e.g. "/logs/latest.log".
     * @param  int    $maxBytes  Refuse files larger than this.
     */
    public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string;

    /**
     * Write a whole file (create or replace) through Wings (#21).
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function writeFile(string $serverUuid, string $path, string $content): void;
}
