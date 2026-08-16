<?php

namespace ServerSuite\Services;

use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ServerModPendingUpdate;
use ServerSuite\Support\Data\ModResult;
use ServerSuite\Support\Data\ModVersion;

/**
 * Manages the per-server mod manifest (server_mods + server_mod_pending_updates).
 *
 * Phase 1: record/remove/pending helpers.
 * Full dependency-graph logic: Phase 6.
 */
class ManifestService
{
    /**
     * Record a newly installed mod in the manifest.
     */
    public function record(
        string $serverUuid,
        ModResult $mod,
        ModVersion $version,
        string $targetFilename,
        string $installPath,
        int $installedBy
    ): ServerMod {
        return ServerMod::updateOrCreate(
            [
                'server_uuid' => $serverUuid,
                'source'      => $mod->source,
                'source_id'   => $mod->id,
            ],
            [
                'mod_name'          => $mod->name,
                'mod_slug'          => $mod->slug,
                'icon_url'          => $mod->iconUrl,
                'page_url'          => $mod->pageUrl,
                'installed_version' => $version->versionNumber,
                'game_version'      => $version->gameVersions[0] ?? null,
                'loader'            => $version->loaders[0] ?? null,
                'target_filename'   => $targetFilename,
                'install_path'      => $installPath,
                'dependencies'      => $version->dependencies,
                'installed_by'      => $installedBy,
            ]
        );
    }

    /**
     * Remove a mod entry from the manifest.
     */
    public function remove(ServerMod $serverMod): void
    {
        $serverMod->delete();
    }

    /**
     * Drop every tracked mod for a server. Used after a clean-slate wipe, where
     * all files are gone, so the manifest must be emptied to match the disk
     * (auto-reconcile). Returns how many records were removed.
     */
    public function clearAll(string $serverUuid): int
    {
        $mods = ServerMod::forServer($serverUuid)->get();
        foreach ($mods as $mod) {
            $this->remove($mod);
        }

        return $mods->count();
    }

    /**
     * Flag that an update is available for an installed mod.
     */
    public function flagPendingUpdate(ServerMod $serverMod, ModVersion $version): ServerModPendingUpdate
    {
        return ServerModPendingUpdate::updateOrCreate(
            ['server_mod_id' => $serverMod->id],
            [
                'available_version' => $version->versionNumber,
                'download_url'      => $version->downloadUrl,
                'changelog'         => $version->changelog,
                'dismissed'         => false,
            ]
        );
    }

    /**
     * Dismiss the pending update notification for a mod.
     */
    public function dismissUpdate(ServerMod $serverMod): void
    {
        $serverMod->pendingUpdate()?->update(['dismissed' => true]);
    }

    /**
     * Clear the pending update record after a successful update.
     */
    public function clearPendingUpdate(ServerMod $serverMod): void
    {
        $serverMod->pendingUpdate()->delete();
    }
}
