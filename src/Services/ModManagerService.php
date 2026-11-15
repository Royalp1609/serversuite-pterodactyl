<?php

namespace ServerSuite\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Exceptions\RepositoryException;

/**
 * Central orchestrator for all mod management operations.
 *
 * Phase 2: search() fans out across the enabled repositories for a server,
 * merges, de-duplicates, and ranks the results.
 *
 * install/update/remove are implemented in Phase 3.
 */
class ModManagerService
{
    public function __construct(
        protected RepositoryManager $repositories,
        protected LoaderDetectionService $loaderDetection,
        protected FileDeliveryService $fileDelivery,
        protected ManifestService $manifest,
        protected InstallPlanner $planner,
    ) {}

    /**
     * Build an install plan (read-only) for a mod version on a server.
     */
    public function planInstall(string $source, string $modId, string $versionId, object $server): \ServerSuite\Support\Data\InstallPlan
    {
        return $this->planner->plan($source, $modId, $versionId, $server);
    }

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Plan several installs as one (the install queue): each queued mod is
     * planned on its own, then the plans are merged so every mod appears
     * once. A queued mod wins over the same mod pulled in as another's
     * dependency, so the version the user picked is the one installed.
     *
     * @param array<int, array{source: string, mod_id: string, version_id: string}> $items
     */
    public function planBatch(array $items, object $server): \ServerSuite\Support\Data\InstallPlan
    {
        $all = [];
        foreach ($items as $item) {
            foreach ($this->planner->plan($item['source'], $item['mod_id'], $item['version_id'], $server)->items as $planned) {
                $all[] = $planned;
            }
        }
        usort($all, fn ($a, $b) => ($a->kind === 'primary' ? 0 : 1) <=> ($b->kind === 'primary' ? 0 : 1));

        $merged = [];
        foreach ($all as $planned) {
            $merged[$planned->source . ':' . $planned->modId] ??= $planned;
        }

        return new \ServerSuite\Support\Data\InstallPlan(array_values($merged));
    }

    /**
     * Search every repository enabled for this server, using the server's
     * detected/overridden loader and game version as filters.
     *
     * Each driver is queried independently; a failure in one driver is logged
     * and skipped rather than failing the whole search.
     *
     * @return ModResult[] Ranked, de-duplicated results.
     */
    public function search(string $query, object $server, int $limit = 20, ?string $loaderOverride = null, ?string $versionOverride = null, ?string $sourceOnly = null, ?string $sort = null, int $offset = 0, bool $compatibleOnly = false): array
    {
        return $this->searchWithContext($query, $server, $limit, $loaderOverride, $versionOverride, $sourceOnly, $sort, $offset, $compatibleOnly)['data'];
    }

    /**
     * Search and also return detection context so the UI can warn when the
     * loader filter is uncertain (e.g. "showing results for all loaders").
     *
     * $loaderOverride / $versionOverride let the UI's filter bar override the
     * server's detected/stored loader and game version for a single search,
     * without persisting the change. When null, the stored/detected values are
     * used as before.
     *
     * @return array{data: ModResult[], next_offset: int, has_more: bool, loader: ?string, loader_confident: bool, game_version: ?string}
     */
    public function searchWithContext(string $query, object $server, int $limit = 20, ?string $loaderOverride = null, ?string $versionOverride = null, ?string $sourceOnly = null, ?string $sort = null, int $offset = 0, bool $compatibleOnly = false): array
    {
        $settings = ModManagerSetting::forServer($server->uuid);
        $detection = null;

        // An explicit override from the UI filter bar always wins and is treated
        // as confident (the user chose it deliberately).
        if ($loaderOverride !== null && $loaderOverride !== '') {
            $loader          = $loaderOverride;
            $loaderConfident = true;
        } else {
            // Resolve loader: stored value wins; otherwise run detailed detection.
            $loader           = $settings->loader;
            $loaderConfident  = (bool) $settings->loader; // a stored value is treated as trusted

            if (! $loader) {
                $detection = $this->loaderDetection->detectDetailed($server);
                $loader          = $detection->loader;            // may be null
                $loaderConfident = $detection->isConfident();

                // Persist only confident detections (mirrors detect()).
                if ($detection->isConfident()) {
                    $settings->update([
                        'loader'       => $detection->loader,
                        'game_version' => $detection->gameVersion ?? $settings->game_version,
                    ]);
                }
            }
        }

        // Version override wins; then a live detection (fresh after a version
        // switch); the stored setting is only the last-resort fallback.
        $gameVersion = ($versionOverride !== null && $versionOverride !== '')
            ? $versionOverride
            : ($detection?->gameVersion ?? $settings->game_version);

        $enabled     = $settings->enabledRepositories();

        // When the UI picks a specific provider, search ONLY that repo rather
        // than fanning out and post-filtering — more correct and avoids showing
        // results from other repos when the chosen one returns nothing.
        if ($sourceOnly !== null && $sourceOnly !== '') {
            $enabled = array_values(array_filter($enabled, fn ($r) => $r === $sourceOnly));
        }

        $drivers     = $this->repositories->enabledDrivers($enabled);

        if ($compatibleOnly && $gameVersion) {
            [$data, $nextOffset, $hasMore] = $this->searchCompatible(
                $drivers, $query, $loader, $gameVersion, $limit, $sort, $offset
            );
        } else {
            // Each repository returns up to $limit and moves on by $limit, so
            // keep everything it returned: cutting the merged list to $limit
            // dropped results no page ever showed (#124). More pages exist
            // while any repository returned a full page (dedupe can shrink one).
            [$batch, $full] = $this->fanOutCounted($drivers, $query, $loader, $gameVersion, $limit, $sort, $offset);
            $data       = $this->rankAndDedupe($batch, $query, max($batch->count(), 1), $sort);
            $nextOffset = $offset + $limit;
            $hasMore    = $full;
        }

        return [
            'data'             => $data,
            'next_offset'      => $nextOffset,
            'has_more'         => $hasMore,
            'loader'           => $loader,
            'loader_confident' => $loaderConfident,
            'game_version'     => $gameVersion,
        ];
    }

    /**
     * fanOut(), plus whether any repository returned a whole page (so it has
     * more) (#124).
     *
     * @return array{0: Collection, 1: bool}
     */
    private function fanOutCounted(array $drivers, string $query, ?string $loader, ?string $gameVersion, int $limit, ?string $sort, int $offset): array
    {
        $out = collect();
        $full = false;
        foreach ($drivers as $driver) {
            $one = $this->fanOut([$driver], $query, $loader, $gameVersion, $limit, $sort, $offset);
            $full = $full || $one->count() >= $limit;
            $out = $out->concat($one);
        }

        return [$out, $full];
    }

    /**
     * One round of fetching across all enabled drivers at a given offset.
     * A failure in one driver is logged and skipped, never fails the search.
     */
    private function fanOut(array $drivers, string $query, ?string $loader, ?string $gameVersion, int $limit, ?string $sort, int $offset): Collection
    {
        $out = collect();

        foreach ($drivers as $driver) {
            try {
                $out = $out->concat($driver->search($query, $loader, $gameVersion, $limit, $sort, $offset));
            } catch (RepositoryException $e) {
                Log::warning("[Server Suite] search failed on {$driver->key()}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                Log::error("[Server Suite] unexpected search error on {$driver->key()}: {$e->getMessage()}");
            }
        }

        return $out;
    }

    /**
     * Over-fetch and keep only results compatible with $gameVersion, scanning
     * forward through provider pages until a page's worth of compatible results
     * is gathered or a scan cap is hit. This is what lets "compatible only"
     * return a full page instead of the survivors of a single provider page
     * (e.g. Hangar, which doesn't filter by version server-side).
     *
     * Nothing is truncated — everything gathered in the window is returned and
     * the next page resumes at the provider offset where scanning stopped, so
     * no compatible result is ever skipped between pages.
     *
     * @return array{0: ModResult[], 1: int, 2: bool} [data, nextOffset, hasMore]
     */
    private function searchCompatible(array $drivers, string $query, ?string $loader, string $gameVersion, int $limit, ?string $sort, int $offset): array
    {
        $scanCap        = max($limit * 6, 240); // bound upstream calls per UI page
        $providerOffset = $offset;
        $scanned        = 0;
        $collected      = collect();
        $exhausted      = false;

        while ($scanned < $scanCap) {
            $batch = $this->fanOut($drivers, $query, $loader, $gameVersion, $limit, $sort, $providerOffset);
            $providerOffset += $limit;
            $scanned        += $limit;

            if ($batch->isEmpty()) {
                $exhausted = true;
                break;
            }

            $collected = $collected->concat(
                $batch->filter(fn ($r) => $this->isCompatible($r, $gameVersion))
            );

            if ($batch->count() < $limit) { // provider ran out
                $exhausted = true;
                break;
            }
            if ($collected->count() >= $limit) { // page filled
                break;
            }
        }

        $data = $this->rankAndDedupe($collected, $query, max($collected->count(), 1), $sort);

        return [$data, $providerOffset, ! $exhausted];
    }

    /**
     * Whether a result supports the given server version. Mirrors the
     * frontend's compatibility predicate so the backend filter and the UI
     * badges always agree.
     */
    private function isCompatible(object $result, string $version): bool
    {
        $versions = $result->gameVersions ?? [];
        if (empty($versions)) {
            return false;
        }

        foreach ($versions as $entry) {
            if ($this->versionCovers((string) $entry, $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirror of the frontend versionCovers(): exact match, "1.20.x" wildcard,
     * and "1.20-1.21" ranges (the latter two appear on Hangar). A bare major
     * like "1.21" does NOT cover "1.21.4" — intentional, matching the badges.
     */
    private function versionCovers(string $entry, string $v): bool
    {
        if ($entry === $v) {
            return true;
        }

        if (str_ends_with($entry, '.x')) {
            $base = substr($entry, 0, -2);
            return $v === $base || str_starts_with($v, $base . '.');
        }

        // A range only when both ends are plain versions (item 85): "1.21-rc1"
        // or "1.20-pre2" are single pre-release versions, not ranges.
        if (preg_match('/^(\d+(?:\.\d+)*)-(\d+(?:\.\d+)*)$/', $entry, $m) && preg_match('/^\d+(?:\.\d+)*$/', $v)) {
            return $this->cmpVersion($v, $m[1]) >= 0 && $this->cmpVersion($v, $m[2]) <= 0;
        }

        return false;
    }

    private function cmpVersion(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));

        for ($i = 0, $n = max(count($pa), count($pb)); $i < $n; $i++) {   // every part (item 85)
            $d = ($pa[$i] ?? 0) - ($pb[$i] ?? 0);
            if ($d !== 0) {
                return $d <=> 0;
            }
        }

        return 0;
    }

    /**
     * Merge results from multiple repositories.
     *
     * De-duplication: the same mod often exists on both Modrinth and CurseForge.
     * We collapse by normalised name, keeping the variant with more downloads
     * but recording that it is available from multiple sources.
     *
     * Ranking: a blend of search relevance (name match) and popularity.
     *
     * @param  Collection<int, ModResult> $results
     * @return ModResult[]
     */
    protected function rankAndDedupe(Collection $results, string $query, int $limit, ?string $sort = null): array
    {
        $queryLower = mb_strtolower(trim($query));

        // Collapse the same mod listed by two repositories (same normalised
        // name, different source), keeping the most-downloaded copy. Two
        // results from ONE repository are different projects and both stay
        // (#124). groupBy/map preserve first-seen order.
        $deduped = $results
            ->groupBy(fn (ModResult $r) => $this->normaliseName($r->name))
            ->flatMap(function (Collection $group) {
                if ($group->pluck('source')->unique()->count() < 2) {
                    return $group->values();
                }
                $keep = $group->sortByDesc(fn (ModResult $r) => $r->downloads)->first();
                // Other sources' copies go; same-source namesakes of the kept one stay.
                return $group->filter(fn (ModResult $r) => $r === $keep || $r->source === $keep->source)->values();
            });

        // An explicit sort: each repository already returned that order, so
        // merge them by the same key instead of leaving them grouped by
        // source (#124). Ties keep the repositories' own order.
        if ($sort !== null && $sort !== '' && $sort !== 'relevance') {
            $key = match ($sort) {
                'downloads' => fn (ModResult $r) => (float) $r->downloads,
                'follows'   => fn (ModResult $r) => (float) ($r->follows ?? 0),
                'newest'    => fn (ModResult $r) => (float) (strtotime((string) $r->publishedAt) ?: 0),
                'updated'   => fn (ModResult $r) => (float) (strtotime((string) $r->updatedAt) ?: 0),
                default     => null,
            };
            $list = $deduped->values();
            if ($key !== null && $list->pluck('source')->unique()->count() > 1) {
                $list = $list->map(fn ($r, $i) => [$r, $key($r), $i])
                    ->sort(fn ($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]])
                    ->map(fn ($x) => $x[0])->values();
            }

            return $list->take($limit)->values()->all();
        }

        return $deduped
            ->sortByDesc(function (ModResult $r) use ($queryLower) {
                return $this->relevanceScore($r, $queryLower);
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Blended relevance score: exact/prefix name matches rank highest,
     * then a log-scaled popularity boost so a hugely popular near-match
     * can still outrank an obscure exact match.
     */
    protected function relevanceScore(ModResult $r, string $queryLower): float
    {
        $name = mb_strtolower($r->name);

        $nameScore = match (true) {
            $name === $queryLower            => 1000.0,
            str_starts_with($name, $queryLower) => 500.0,
            str_contains($name, $queryLower)    => 250.0,
            default                             => 0.0,
        };

        // log10(downloads+1) keeps popularity influence bounded (0–~8 range)
        $popularityScore = log10(max(1, $r->downloads) + 1);

        return $nameScore + $popularityScore;
    }

    protected function normaliseName(string $name): string
    {
        // Lowercase, strip non-alphanumerics so "JEI" / "jei" / "Just Enough Items"
        // variants at least collapse on punctuation/spacing differences.
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?: $name;
    }

    // -------------------------------------------------------------------------
    // Get available versions for a specific mod (used by the install UI)
    // -------------------------------------------------------------------------

    /**
     * @return ModVersion[]
     */
    public function versionsFor(string $source, string $modId, object $server, ?string $loaderOverride = null, ?string $versionOverride = null): array
    {
        $settings = ModManagerSetting::forServer($server->uuid);

        // Filter-bar selection wins; otherwise fall back to the server's stored
        // loader/version (or detection). This keeps the Versions tab consistent
        // with what the user is actually browsing for.
        $loader = ($loaderOverride !== null && $loaderOverride !== '')
            ? $loaderOverride
            : ($settings->loader ?: $this->loaderDetection->detect($server));

        $gameVersion = ($versionOverride !== null && $versionOverride !== '')
            ? $versionOverride
            : $settings->game_version;

        return $this->repositories
            ->driver($source)
            ->versions($modId, $loader, $gameVersion);
    }

    /**
     * Minecraft game versions for the UI's version dropdown, newest-first,
     * plus the version we'd default-select for this server.
     *
     * Sourced live from Modrinth's version tags (cached, with a static
     * fallback) so new Minecraft releases appear automatically. The "detected"
     * value is the server's stored game version (or detection result) so the
     * dropdown can pre-select it.
     *
     * @return array{versions: string[], detected: ?string, loader: ?string}
     */
    public function gameVersions(object $server): array
    {
        // Sourced live from Modrinth's version tag list so new Minecraft
        // releases appear in the filter automatically; falls back to a static
        // range if the fetch is unavailable (see liveGameVersions below).
        $versions = $this->liveGameVersions();

        $settings = ModManagerSetting::forServer($server->uuid);
        $detected = $settings->game_version;
        $loader   = $settings->loader;

        if (! $detected || ! $loader) {
            $detection = $this->loaderDetection->detectDetailed($server);
            $detected  = $detected ?: $detection->gameVersion;
            $loader    = $loader ?: ($detection->isConfident() ? $detection->loader : null);
        }

        // A sourced version may be newer than the static list (e.g. 26.1.2);
        // prepend it so the dropdown can display and auto-select it.
        if ($detected && ! in_array($detected, $versions, true)) {
            array_unshift($versions, $detected);
        }

        return ['versions' => $versions, 'detected' => $detected, 'loader' => $loader];
    }

    /**
     * The version list for the UI filter, sourced live from Modrinth's tag
     * endpoint (cached). Falls back to a static range if Modrinth is
     * unreachable, so the filter never ends up empty.
     *
     * @return string[]
     */
    private function liveGameVersions(): array
    {
        try {
            $modrinth = $this->repositories->driver('modrinth');
            if ($modrinth instanceof \ServerSuite\Drivers\ModrinthDriver) {
                $live = $modrinth->gameVersionTags();
                if (! empty($live)) {
                    return $live;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[Server Suite] live game-version list unavailable, using fallback: ' . $e->getMessage());
        }

        return $this->fallbackGameVersions();
    }

    /**
     * Static Minecraft version range, used only when the live list can't be
     * fetched. Concrete patch strings, newest-first. Modrinth indexes mods
     * under specific patches (e.g. "1.16.5"), so bare majors aren't offered.
     *
     * @return string[]
     */
    private function fallbackGameVersions(): array
    {
        $minorRange = range(21, 16);
        $patchMax = [16 => 5, 17 => 1, 18 => 2, 19 => 4, 20 => 6, 21 => 11];

        // Each line's first release is "1.21", not "1.21.0" (item 84), so it
        // comes after its patches. Only used when Modrinth's list can't be read.
        $versions = [];
        foreach ($minorRange as $minor) {
            for ($patch = ($patchMax[$minor] ?? 0); $patch >= 1; $patch--) {
                $versions[] = "1.{$minor}.{$patch}";
            }
            $versions[] = "1.{$minor}";
        }

        return $versions;
    }

    /**
     * Run detailed loader detection for a server (loader + confidence + signal).
     */
    public function detectLoader(object $server): \ServerSuite\Support\Data\LoaderDetectionResult
    {
        return $this->loaderDetection->detectDetailed($server);
    }

    // -------------------------------------------------------------------------
    // Install / update / remove
    // -------------------------------------------------------------------------

    /**
     * Install a single mod version synchronously (no dependency resolution).
     * Used for the simple single-mod path where the UI has already confirmed
     * there are no dependencies/conflicts. Returns the manifest entry.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function install(string $modId, string $source, string $versionId, object $server, int $installedBy): ServerMod
    {
        $driver  = $this->repositories->driver($source);
        $settings = ModManagerSetting::forServer($server->uuid);
        $loader   = $settings->loader ?: $this->loaderDetection->detect($server);

        $mod     = $driver->get($modId);
        $modName = $mod?->name ?? $modId;
        $version = $this->resolveVersion($driver, $modId, $versionId, $loader, $settings->game_version, $modName);

        // If this mod is already in the manifest with a different filename,
        // remember the old file so we can remove it after the new one lands.
        $existing = ServerMod::forServer($server->uuid)
            ->where('source', $source)
            ->where('source_id', $modId)
            ->first();

        $result = $this->fileDelivery->deliver($server->uuid, $version, $modName);

        if ($existing
            && $existing->target_filename
            && $existing->target_filename !== $result['filename']) {
            try {
                $this->fileDelivery->remove(
                    $server->uuid,
                    $existing->install_path ?? $result['directory'],
                    $existing->target_filename,
                );
            } catch (\Throwable $e) {
                Log::warning("[Server Suite] could not remove superseded file {$existing->target_filename}: {$e->getMessage()}");
            }
        }

        return $this->manifest->record(
            serverUuid: $server->uuid,
            mod: $mod ?? $this->syntheticModResult($source, $modId, $modName),
            version: $version,
            targetFilename: $result['filename'],
            installPath: $result['directory'],
            installedBy: $installedBy,
        );
    }

    /**
     * Execute a confirmed install plan.
     *
     * Decides sync vs queued:
     *   - a single 'install' item with no dependencies  -> run synchronously
     *   - anything with dependencies or multiple items   -> queue one job per item
     *
     * Returns a batch id (queued) or null (ran synchronously and completed).
     *
     * @param \ServerSuite\Support\Data\InstallPlan $plan
     * @return array{mode: string, batch_id: ?string, installed: int}
     */
    public function executePlan(\ServerSuite\Support\Data\InstallPlan $plan, object $server, int $installedBy, bool $forceQueue = false): array
    {
        $toInstall = $plan->toInstall();

        if (count($toInstall) === 0) {
            return ['mode' => 'noop', 'batch_id' => null, 'installed' => 0];
        }

        // Synchronous fast-path: exactly one mod, no dependencies.
        if (! $forceQueue && count($toInstall) === 1 && $toInstall[0]->kind === 'primary') {
            $item = $toInstall[0];
            $this->install($item->modId, $item->source, $item->versionId, $server, $installedBy);

            return ['mode' => 'sync', 'batch_id' => null, 'installed' => 1];
        }

        // Queued path: create a task per item, dispatch a job each.
        $batchId = (string) \Illuminate\Support\Str::uuid();

        foreach ($toInstall as $item) {
            $task = \ServerSuite\Models\ModInstallTask::create([
                'batch_id'       => $batchId,
                'server_uuid'    => $server->uuid,
                'source'         => $item->source,
                'source_id'      => $item->modId,
                'mod_name'       => $item->modName,
                'version_id'     => $item->versionId,
                'version_number' => $item->versionNumber,
                'kind'           => $item->kind,
                'status'         => \ServerSuite\Models\ModInstallTask::STATUS_PENDING,
                'requested_by'   => $installedBy,
            ]);

            \ServerSuite\Jobs\InstallModJob::dispatch($task->id);
        }

        return ['mode' => 'queued', 'batch_id' => $batchId, 'installed' => count($toInstall)];
    }

    /**
     * Execute one queued task (called by InstallModJob). Resolves the server
     * by UUID, delivers the file, and records the manifest entry.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function executeTask(\ServerSuite\Models\ModInstallTask $task): void
    {
        $server = $this->resolveServer($task->server_uuid);

        $this->install(
            modId: $task->source_id,
            source: $task->source,
            versionId: $task->version_id,
            server: $server,
            installedBy: (int) ($task->requested_by ?? 0),
        );
    }

    /**
     * Update an installed mod to the latest compatible version.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    public function update(ServerMod $serverMod, object $server): ServerMod
    {
        // A disabled mod would come back enabled next to its old .jar.disabled,
        // and a pack mod updates with its pack (#121).
        if ($serverMod->disabled_at !== null) {
            throw \ServerSuite\Exceptions\DeliveryException::daemonError("{$serverMod->mod_name} is disabled. Enable it before updating it.");
        }
        if ($serverMod->modpack_id !== null) {
            throw \ServerSuite\Exceptions\DeliveryException::daemonError("{$serverMod->mod_name} belongs to a modpack and updates with the pack.");
        }

        $driver = $this->repositories->driver($serverMod->source);
        $latest = $this->latestFor($serverMod, $server);

        if (! $latest) {
            throw \ServerSuite\Exceptions\DeliveryException::daemonError('No compatible version available to update to.');
        }

        // Deliver the new file first.
        $mod    = $driver->get($serverMod->source_id);
        $result = $this->fileDelivery->deliver($server->uuid, $latest, $serverMod->mod_name);

        // Remove the old file if its filename differs from the new one.
        if ($serverMod->target_filename && $serverMod->target_filename !== $result['filename']) {
            try {
                $this->fileDelivery->remove($server->uuid, $serverMod->install_path ?? $result['directory'], $serverMod->target_filename);
            } catch (\Throwable $e) {
                Log::warning("[Server Suite] could not remove old file {$serverMod->target_filename}: {$e->getMessage()}");
            }
        }

        // Update the manifest entry and clear any pending-update flag.
        $serverMod->update([
            'installed_version' => $latest->versionNumber,
            'target_filename'   => $result['filename'],
            'install_path'      => $result['directory'],
            'game_version'      => $latest->gameVersions[0] ?? $serverMod->game_version,
        ]);

        $this->manifest->clearPendingUpdate($serverMod);

        return $serverMod->fresh();
    }

    /**
     * The build update() installs for this mod (#120). The scheduled check
     * asks the same question, so what it flags is what an update installs:
     * the mod's loader (else the server's), and the server's game version.
     */
    public function latestFor(ServerMod $serverMod, object $server, ?ModManagerSetting $settings = null): ?ModVersion
    {
        $settings ??= ModManagerSetting::forServer($server->uuid);
        $loader = $serverMod->loader ?: ($settings->loader ?: $this->loaderDetection->detect($server));

        return $this->repositories->driver($serverMod->source)
            ->latestVersion($serverMod->source_id, $loader, $settings->game_version);
    }

    /**
     * Flag (or clear) each installed mod's pending update for one server
     * (#120). Pack mods update with their pack, so their flags are cleared.
     * Mods from a repository the server has turned off are left as they are.
     * A dismissed update stays dismissed until a newer version appears.
     *
     * @return array{checked: int, updates: int, errors: int, flagged: array<int, string>}
     */
    public function checkUpdates(object $server): array
    {
        $settings = ModManagerSetting::forServer($server->uuid);
        $enabled  = $settings->enabledRepositories();
        $checked = $updates = $errors = 0;
        $flagged = [];

        foreach ($this->installed($server->uuid) as $mod) {
            if ($mod->modpack_id !== null) {
                $mod->pendingUpdate?->delete();
                continue;
            }
            if (! in_array($mod->source, $enabled, true)) {
                continue;
            }

            try {
                $driver = $this->repositories->driver($mod->source);
                if (! $driver->isAvailable()) {
                    continue;
                }
                $latest = $this->latestFor($mod, $server, $settings);
            } catch (\Throwable $e) {
                $errors++;
                Log::warning('[Server Suite] update check failed', ['mod' => $mod->id, 'error' => $e->getMessage()]);
                continue;
            }
            $checked++;

            $number = $latest?->versionNumber;
            if ($number === null || $number === '' || $number === $mod->installed_version) {
                $mod->pendingUpdate?->delete();
                continue;
            }

            $updates++;
            $flagged[] = "{$mod->mod_name}: {$mod->installed_version} \u{2192} {$number}";
            $pending = $mod->pendingUpdate;
            if ($pending !== null && $pending->available_version === $number) {
                continue;   // same version: keep it (and a dismissal) as it is
            }
            $this->manifest->flagPendingUpdate($mod, $latest);
        }

        return ['checked' => $checked, 'updates' => $updates, 'errors' => $errors, 'flagged' => $flagged];
    }

    /**
     * Installed mods outside $mods that need one of them (the bulk remove's
     * warning). Each with the names of the mods in $mods it needs.
     *
     * @param ServerMod[] $mods  all on one server
     * @return array<int, array{id: int, mod_name: string, needs: string[]}>
     */
    public function dependentsOfSet(array $mods): array
    {
        if ($mods === []) {
            return [];
        }
        $ids = array_map(fn (ServerMod $m) => $m->id, $mods);
        $out = [];
        foreach (ServerMod::forServer($mods[0]->server_uuid)->whereNotIn('id', $ids)->get() as $other) {
            $deps = is_array($other->dependencies) ? $other->dependencies : [];
            $needs = [];
            foreach ($mods as $m) {
                if ($other->source === $m->source && in_array($m->source_id, $deps, true)) {
                    $needs[] = $m->mod_name;
                }
            }
            if ($needs !== []) {
                $out[] = ['id' => $other->id, 'mod_name' => $other->mod_name, 'needs' => $needs];
            }
        }

        return $out;
    }

    /**
     * Remove an installed mod from a server and the manifest.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     */
    /**
     * Compute which installed mods would become ORPHANED if the given mod were
     * removed — i.e. dependencies that nothing else (still installed) requires.
     *
     * Recursive / fixpoint: removing X may orphan its dependency Y; if Y was the
     * only thing needing Z, then Z is now orphaned too. We iterate until no new
     * orphans appear. Shared dependencies (still needed by something outside the
     * removal set) are never included.
     *
     * The target mod itself is NOT included in the result — only the orphans.
     *
     * @return ServerMod[] orphaned dependencies, safe to offer for removal
     */
    public function orphanedBy(ServerMod $serverMod): array
    {
        return $this->orphanedBySet([$serverMod]);
    }

    /**
     * Dependencies that nothing installed needs once every mod in $mods is
     * removed (the bulk remove): a library two of them share counts, which
     * removing either one alone wouldn't orphan. Excludes $mods themselves.
     *
     * @param ServerMod[] $mods  all on one server
     * @return ServerMod[]
     */
    public function orphanedBySet(array $mods): array
    {
        if ($mods === []) {
            return [];
        }
        $all = ServerMod::forServer($mods[0]->server_uuid)->get();

        // Index by "source:source_id" for dependency lookups.
        $byKey = [];
        foreach ($all as $m) {
            $byKey["{$m->source}:{$m->source_id}"] = $m;
        }

        $depsOf = function (ServerMod $m): array {
            return is_array($m->dependencies) ? $m->dependencies : [];
        };

        // The set being removed; seed with the targets, grow with orphans.
        $removing = [];
        foreach ($mods as $m) {
            $removing[$m->id] = $m;
        }

        do {
            $added = false;

            // Candidate orphans: direct dependencies of everything in the
            // removal set that are themselves installed and not already queued.
            $candidates = [];
            foreach ($removing as $m) {
                foreach ($depsOf($m) as $depId) {
                    $depMod = $byKey["{$m->source}:{$depId}"] ?? null;
                    if ($depMod && ! isset($removing[$depMod->id])) {
                        $candidates[$depMod->id] = $depMod;
                    }
                }
            }

            // A candidate is orphaned only if NOTHING still-installed (i.e. not
            // in the removal set) depends on it.
            foreach ($candidates as $cand) {
                $stillNeeded = false;
                foreach ($all as $other) {
                    if (isset($removing[$other->id]) || $other->id === $cand->id) {
                        continue; // skip mods being removed and itself
                    }
                    if ($other->source === $cand->source
                        && in_array($cand->source_id, $depsOf($other), true)) {
                        $stillNeeded = true;
                        break;
                    }
                }

                if (! $stillNeeded) {
                    $removing[$cand->id] = $cand;
                    $added = true;
                }
            }
        } while ($added);

        // Return the orphans only (exclude the targets).
        foreach ($mods as $m) {
            unset($removing[$m->id]);
        }

        return array_values($removing);
    }

    /**
     * Installed mods on this server that declare the given mod as a required
     * dependency. Used to warn before removing something others rely on.
     *
     * @return ServerMod[]
     */
    public function dependentsOf(ServerMod $serverMod): array
    {
        return ServerMod::forServer($serverMod->server_uuid)
            ->where('id', '!=', $serverMod->id)
            ->get()
            ->filter(function (ServerMod $other) use ($serverMod) {
                $deps = is_array($other->dependencies) ? $other->dependencies : [];
                // dependencies are stored as source-ids in the same source namespace
                return $other->source === $serverMod->source
                    && in_array($serverMod->source_id, $deps, true);
            })
            ->values()
            ->all();
    }

    /**
     * Remove an installed mod from a server and the manifest.
     *
     * If other installed mods depend on this one, removal is refused unless
     * $force is true — the UI surfaces the dependents and asks the user to
     * confirm before passing force.
     *
     * @throws \ServerSuite\Exceptions\DeliveryException
     * @throws \RuntimeException when dependents exist and $force is false
     */
    public function remove(ServerMod $serverMod, object $server, bool $force = false): void
    {
        if (! $force) {
            $dependents = $this->dependentsOf($serverMod);
            if (count($dependents) > 0) {
                $names = implode(', ', array_map(fn (ServerMod $m) => $m->mod_name, $dependents));
                throw new \RuntimeException(
                    "{$serverMod->mod_name} is required by: {$names}. Removing it may break them."
                );
            }
        }

        $this->fileDelivery->remove(
            $server->uuid,
            $serverMod->install_path ?? $this->fileDelivery->targetDirectory($server->uuid),
            $serverMod->target_filename,
        );

        // Best-effort: also remove the plugin/mod's leftover config folders so
        // removal is clean. Never allowed to break the removal itself.
        try {
            $this->fileDelivery->sweepConfigs($server->uuid, $serverMod->mod_name);
        } catch (\Throwable $e) {
            // swallow — cleanup is a nicety, not a requirement
        }

        $this->manifest->remove($serverMod);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** The version the user picked, or a clear error; never a different one (#138). */
    protected function resolveVersion($driver, string $modId, string $versionId, ?string $loader, ?string $gameVersion, ?string $modName = null): ModVersion
    {
        return \ServerSuite\Support\ChosenVersion::resolve($driver, $modId, $versionId, $loader, $gameVersion, $modName ?? $modId);
    }

    /**
     * Resolve the panel's Server model by UUID without coupling to its class
     * at the type level. Tries common panel model classes.
     */
    /** The panel's server model for a uuid (or an object carrying just the uuid). */
    public function serverFor(string $serverUuid): object
    {
        return $this->resolveServer($serverUuid);
    }

    protected function resolveServer(string $serverUuid): object
    {
        $panel = false;
        foreach (['\Pterodactyl\Models\Server', '\App\Models\Server'] as $class) {
            if (class_exists($class)) {
                $panel = true;
                $server = $class::query()->where('uuid', $serverUuid)->first();
                if ($server) {
                    return $server;
                }
            }
        }
        // On a panel, a server that isn't there is gone (item 94): stop rather
        // than carry on with a stand-in that has only a uuid.
        if ($panel) {
            throw new \ServerSuite\Exceptions\DeliveryException("Server {$serverUuid} no longer exists.");
        }

        // No panel models loaded (tests, CLI tools): an object carrying the uuid.
        return (object) ['uuid' => $serverUuid];
    }

    /**
     * Build a minimal ModResult when the repository lookup failed but we still
     * need to record a manifest entry.
     */
    protected function syntheticModResult(string $source, string $modId, string $modName): ModResult
    {
        return new ModResult(
            source: $source,
            id: $modId,
            slug: $modId,
            name: $modName,
            description: '',
            author: '',
            downloads: 0,
            iconUrl: null,
            pageUrl: null,
            loaders: [],
            gameVersions: [],
            dependencies: [],
        );
    }

    // -------------------------------------------------------------------------
    // Manifest read helpers
    // -------------------------------------------------------------------------

    /**
     * Apply all pending updates for a server in one pass. Reuses the validated
     * single-mod update() for each, collecting per-mod results so a failure in
     * one doesn't abort the rest.
     *
     * @return array{updated: array, failed: array}
     */
    public function updateAll(object $server): array
    {
        $mods = $this->applicableUpdates($server->uuid);

        $updated = [];
        $failed  = [];

        foreach ($mods as $mod) {
            try {
                $result = $this->update($mod, $server);
                $updated[] = [
                    'mod_name'          => $result->mod_name,
                    'installed_version' => $result->installed_version,
                ];
            } catch (\Throwable $e) {
                $failed[] = [
                    'mod_name' => $mod->mod_name,
                    'error'    => $e->getMessage(),
                ];
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /**
     * The mods "Update all" would update: a pending, undismissed update, not
     * from a modpack (they update with the pack), not disabled (#121).
     */
    public function applicableUpdates(string $serverUuid): \Illuminate\Support\Collection
    {
        return ServerMod::forServer($serverUuid)->with('pendingUpdate')->get()
            ->filter(fn (ServerMod $m) => $m->pendingUpdate !== null && ! $m->pendingUpdate->dismissed
                && $m->modpack_id === null && $m->disabled_at === null)
            ->values();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ServerMod> */
    public function installed(string $serverUuid): \Illuminate\Database\Eloquent\Collection
    {
        return ServerMod::forServer($serverUuid)->with('pendingUpdate')->get();
    }

    public function settings(string $serverUuid): ModManagerSetting
    {
        return ModManagerSetting::forServer($serverUuid);
    }
}
