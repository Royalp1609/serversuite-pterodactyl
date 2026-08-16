<?php

namespace ServerSuite\Services;

use ServerSuite\Support\RepositoryManager;
use ServerSuite\Support\Data\InstallPlan;
use ServerSuite\Support\Data\InstallPlanItem;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModManagerSetting;

/**
 * Builds an install plan for a mod: resolves its dependency tree, checks each
 * resolved mod against what's already installed on the server, and tags every
 * node with a status (install / satisfied / conflict / manual).
 *
 * This is READ-ONLY. It touches no files and writes nothing. The UI presents
 * the plan and the user confirms before anything executes — satisfying the
 * "prompt with the dependency list first" and "notify on conflict" rules.
 *
 * Dependency resolution is breadth-first with cycle protection, bounded by a
 * max depth so a pathological dependency graph can't run away.
 */
class InstallPlanner
{
    private const MAX_DEPTH = 5;

    public function __construct(
        protected RepositoryManager $repositories,
        protected LoaderDetectionService $loaderDetection,
    ) {}

    /**
     * Plan the installation of a specific mod version onto a server.
     */
    public function plan(string $source, string $modId, string $versionId, object $server): InstallPlan
    {
        $settings    = ModManagerSetting::forServer($server->uuid);
        $loader      = $settings->loader ?: $this->loaderDetection->detect($server);
        $gameVersion = $settings->game_version;

        // Index currently-installed mods by "source:source_id" for O(1) lookups.
        $installed = ServerMod::forServer($server->uuid)
            ->get()
            ->keyBy(fn (ServerMod $m) => "{$m->source}:{$m->source_id}");

        $items   = [];
        $visited = [];  // "source:modId" => true, prevents cycles & dupes

        // BFS queue: each entry is [source, modId, versionId|null, kind, depth]
        $queue = [[$source, $modId, $versionId, 'primary', 0]];

        while ($queue) {
            [$src, $mid, $vid, $kind, $depth] = array_shift($queue);

            $key = "{$src}:{$mid}";
            if (isset($visited[$key]) || $depth > self::MAX_DEPTH) {
                continue;
            }
            $visited[$key] = true;

            $driver = $this->repositories->driver($src);

            // Resolve the version we'd install. For the primary mod we were
            // handed an explicit versionId; for dependencies we pick the latest
            // compatible version.
            $version = $vid
                ? $this->findVersion($driver, $mid, $vid, $loader, $gameVersion)
                : $driver->latestVersion($mid, $loader, $gameVersion);

            $modName = $this->resolveName($driver, $mid, $src);

            if (! $version) {
                // No compatible version exists for this loader/game version.
                $items[] = new InstallPlanItem(
                    source: $src,
                    modId: $mid,
                    modName: $modName,
                    versionId: $vid ?? '',
                    versionNumber: '',
                    status: 'manual',
                    kind: $kind,
                    reason: 'No compatible version found for this loader / game version.',
                );
                continue;
            }

            $items[] = $this->classify($src, $mid, $modName, $version, $kind, $installed);

            // Enqueue this version's required dependencies.
            foreach ($this->requiredDependencies($version, $src) as $depId) {
                $depKey = "{$src}:{$depId}";
                if (! isset($visited[$depKey])) {
                    $queue[] = [$src, $depId, null, 'dependency', $depth + 1];
                }
            }
        }

        return new InstallPlan($items);
    }

    // -------------------------------------------------------------------------

    /**
     * Decide the status of a single resolved mod against the manifest.
     */
    protected function classify(
        string $source,
        string $modId,
        string $modName,
        ModVersion $version,
        string $kind,
        $installed
    ): InstallPlanItem {
        // No public download URL -> can't auto-install.
        if (trim($version->downloadUrl) === '') {
            return new InstallPlanItem(
                source: $source,
                modId: $modId,
                modName: $modName,
                versionId: $version->versionId,
                versionNumber: $version->versionNumber,
                status: 'manual',
                kind: $kind,
                reason: 'The author has disabled third-party downloads; install manually.',
            );
        }

        $existing = $installed->get("{$source}:{$modId}");

        if ($existing) {
            if ($existing->installed_version === $version->versionNumber) {
                return new InstallPlanItem(
                    source: $source,
                    modId: $modId,
                    modName: $modName,
                    versionId: $version->versionId,
                    versionNumber: $version->versionNumber,
                    status: 'satisfied',
                    kind: $kind,
                    installedVersion: $existing->installed_version,
                );
            }

            // Installed, but a different version.
            // With auto-update enabled, treat as a fresh install (overwrites);
            // otherwise surface a conflict for the user to resolve.
            if (config('serversuite.install.auto_update_on_conflict', false)) {
                return new InstallPlanItem(
                    source: $source,
                    modId: $modId,
                    modName: $modName,
                    versionId: $version->versionId,
                    versionNumber: $version->versionNumber,
                    status: 'install',
                    kind: $kind,
                    installedVersion: $existing->installed_version,
                    reason: "Auto-updating from {$existing->installed_version}.",
                );
            }

            return new InstallPlanItem(
                source: $source,
                modId: $modId,
                modName: $modName,
                versionId: $version->versionId,
                versionNumber: $version->versionNumber,
                status: 'conflict',
                kind: $kind,
                installedVersion: $existing->installed_version,
                reason: "Already installed at {$existing->installed_version}.",
            );
        }

        // Fresh install.
        return new InstallPlanItem(
            source: $source,
            modId: $modId,
            modName: $modName,
            versionId: $version->versionId,
            versionNumber: $version->versionNumber,
            status: 'install',
            kind: $kind,
        );
    }

    /**
     * Find a specific version by id from a driver's version list.
     */
    protected function findVersion($driver, string $modId, string $versionId, ?string $loader, ?string $gameVersion): ?ModVersion
    {
        foreach ($driver->versions($modId, $loader, $gameVersion) as $v) {
            if ($v->versionId === $versionId) {
                return $v;
            }
        }

        // Fall back to latest if the requested id isn't in the compatible set.
        return $driver->latestVersion($modId, $loader, $gameVersion);
    }

    /**
     * Required dependency source-ids for a version.
     *
     * @return string[]
     */
    protected function requiredDependencies(ModVersion $version, string $source): array
    {
        return $version->dependencies;
    }

    protected function resolveName($driver, string $modId, string $source): string
    {
        try {
            $mod = $driver->get($modId);
            return $mod?->name ?? $modId;
        } catch (\Throwable) {
            return $modId;
        }
    }
}
