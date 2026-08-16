<?php

namespace ServerSuite\Contracts;

use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * Every repository driver (Modrinth, CurseForge, Hangar, …) must implement
 * this interface. The ModManagerService only ever talks to this contract,
 * keeping all provider-specific logic in the driver classes.
 */
interface RepositoryContract
{
    /**
     * A short, unique key identifying this provider (e.g. 'modrinth').
     */
    public function key(): string;

    /**
     * Human-readable name shown in the UI (e.g. 'Modrinth').
     */
    public function label(): string;

    /**
     * Search this repository.
     *
     * @param  string      $query       Free-text search term.
     * @param  string|null $loader      Loader slug (fabric|forge|paper …) or null for any.
     * @param  string|null $gameVersion Minecraft/game version string or null for any.
     * @param  int         $limit       Maximum results to return.
     * @return ModResult[]
     */
    public function search(
        string $query,
        ?string $loader = null,
        ?string $gameVersion = null,
        int $limit = 20,
        ?string $sort = null,
        int $offset = 0
    ): array;

    /**
     * Fetch full details for a single mod by its repository ID.
     */
    public function get(string $modId): ?ModResult;

    /**
     * Identify a mod version by the SHA1 of its file, for reconciliation.
     *
     * Returns ['mod' => ModResult, 'version' => ModVersion] on an exact hit,
     * or null. Repositories without a hash-lookup endpoint simply return null.
     */
    public function lookupByHash(string $sha1): ?array;

    /**
     * List available versions for a mod, optionally filtered by loader/game version.
     *
     * @return ModVersion[]
     */
    public function versions(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): array;

    /**
     * Return the latest compatible version for a mod.
     */
    public function latestVersion(
        string $modId,
        ?string $loader = null,
        ?string $gameVersion = null
    ): ?ModVersion;

    /**
     * Whether this repository requires an API key and that key is configured.
     */
    public function isAvailable(): bool;
}
