<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Support\ReinstallGuard;

/**
 * Orchestrates a backup-gated server version change (Phase 2, non-nuclear).
 *
 * The switch is intentionally NOT one long blocking call — a reinstall can take
 * minutes. The backup is taken and polled to completion by the caller FIRST;
 * `apply()` then fires only quick triggers (write variables → set image →
 * reinstall) and returns, leaving the (async) reinstall to finish on its own.
 * The caller polls install state afterwards.
 *
 * Pinning: BUILD_NUMBER is set to the EXACT build resolved from Paper v3, never
 * "latest", so a version change is reproducible and a downgrade actually sticks.
 */
class VersionSwitchService
{
    public function __construct(
        private ServerVersionService $versions,
        private PanelServerOps $ops,
        private FileDeliveryService $files,
        private LoaderSwitchService $loaders,
        private ManifestService $manifest,
    ) {
    }

    /**
     * Apply a version change. Quick: validates, (optionally) enforces the backup
     * gate, writes the pinned variables + image, and triggers the reinstall.
     *
     * @param object $server         panel Server model (preflight needs egg/image)
     * @param string $targetVersion  e.g. "1.20.6" or "26.1"
     * @param int|null $targetBuild  specific build, or null = preferred stable
     * @param bool $requireBackup    enforce a recent completed backup first
     *
     * @return array  the action taken (for the caller to begin polling install)
     */
    public function apply(object $server, string $targetVersion, ?int $targetBuild = null, bool $requireBackup = true, bool $deletePlugins = true, string $wipeMode = 'none'): array
    {
        $uuid = $server->uuid;
        $wipeMode = in_array($wipeMode, ['reset_world', 'clean_slate'], true) ? $wipeMode : 'none';
        $wipe = $wipeMode !== 'none';

        // Preflight also reports this, but enforce it here, before the stop /
        // wipe / variable changes below: they're only safe if the reinstall
        // that finishes the switch can actually run (#3).
        ReinstallGuard::assertCanReinstall($server, 'change version');

        $pf = $this->versions->preflight($server, $targetVersion, $targetBuild);

        if (! ($pf['can_proceed'] ?? false)) {
            throw new \RuntimeException('Cannot change version: ' . implode(' ', $pf['blockers'] ?? ['preflight failed.']));
        }

        // Safety gate: a real, completed backup must exist before we reinstall.
        // The caller takes it (a "Pre-switch → …" backup) and polls it complete,
        // then calls apply; we re-verify server-side so the gate can't be skipped
        // by a misbehaving client.
        if ($requireBackup && ! $this->hasRecentCompletedBackup($uuid)) {
            throw new \RuntimeException('A completed backup is required before changing version. Take one first.');
        }

        // A file wipe must run with the server stopped. Drive it as a small
        // state machine: if still running, fire a stop and report back so the
        // caller polls status and calls again once offline. (No wipe -> the
        // reinstall handles power itself, so this dance is skipped.)
        if ($wipe) {
            $state = $this->ops->runtimeState($uuid);
            if (($state['power'] ?? null) !== 'offline') {
                try {
                    $this->ops->power($uuid, 'stop');
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Could not stop the server before wiping: ' . $e->getMessage());
                }

                return ['status' => 'stopping', 'wipe_mode' => $wipeMode];
            }
        }

        $build = $pf['target']['build'] ?? null;
        if ($build === null) {
            throw new \RuntimeException("No build resolved for {$targetVersion}.");
        }

        // File handling. A wipe supersedes plain plugin removal; on reset_world
        // the caller may still opt to clear plugin jars too (useful downgrading).
        $wiped = [];
        $pluginsRemoved = [];
        if ($wipe) {
            $wiped = $this->files->wipeForNuclear($uuid, $wipeMode === 'clean_slate' ? 'clean_slate' : 'keep_plugins');
            if ($wipeMode === 'reset_world' && $deletePlugins) {
                $pluginsRemoved = $this->files->deletePluginJars($uuid);
            }
            // Clean slate removes every mod file, so empty the manifest to match.
            if ($wipeMode === 'clean_slate') {
                try {
                    $this->manifest->clearAll($uuid);
                } catch (\Throwable $e) {
                    // non-fatal
                }
            }
        } elseif ($deletePlugins) {
            // Plugins built for the old version very commonly halt boot on the
            // new one. Configs are kept. "Keep anyway" is the caller's choice.
            $pluginsRemoved = $this->files->deletePluginJars($uuid);
        }

        // 1. Pin the exact version + build the egg's install script will fetch.
        $this->ops->setServerVariables($uuid, [
            'MINECRAFT_VERSION' => $targetVersion,
            'BUILD_NUMBER'      => (string) $build,
        ]);

        // 2. Move the Docker image only when the Java requirement actually changes.
        $imageChanged = false;
        if (($pf['image']['change'] ?? false) && ! empty($pf['image']['resolved'])) {
            $this->ops->setDockerImage($uuid, $pf['image']['resolved']);
            $imageChanged = true;
        }

        // 3. Trigger the reinstall (re-runs the egg install script, which reads
        //    the variables above and downloads the matching jar). Async — the
        //    caller polls runtimeState().install until it clears.
        $this->ops->reinstall($uuid);

        // Keep the stored setting in step so the Mods tab's compatibility filter
        // targets the new version immediately (detection is skipped when a loader
        // is already stored, so the stale stored value would otherwise linger).
        try {
            \ServerSuite\Models\ModManagerSetting::forServer($uuid)->update([
                'game_version' => $targetVersion,
            ]);
        } catch (\Throwable $e) {
            // non-fatal — detection will still correct it on the next tab load
        }

        return [
            'status'          => 'reinstalling',
            'target'          => ['version' => $targetVersion, 'build' => $build],
            'image_changed'   => $imageChanged,
            'image'           => $imageChanged ? $pf['image']['resolved'] : ($server->image ?? null),
            'direction'       => $pf['direction'] ?? 'unknown',
            'wipe_mode'       => $wipeMode,
            'wiped'           => $wiped,
            'plugins_removed' => $pluginsRemoved,
        ];
    }

    /**
     * Re-align the server's version to the one stamped in a restored backup's
     * name (e.g. "v1.20.6 Paper · Auto …"), so the restored world and the
     * server's version settings match — a mismatched jar/world is exactly the
     * corruption case restores otherwise create.
     *
     * Best-effort: an unstamped/unparseable name is a no-op, and every step is
     * guarded so it can never turn a successful restore into an error.
     *
     * @return array{version: string, loader: ?string, image_changed: bool}|null
     */
    public function alignToBackupVersion(object $server, ?string $backupName): ?array
    {
        if (! $backupName || ! preg_match('/^v(\d+(?:\.\d+)+)(?:\s+([A-Za-z0-9]+))?/', $backupName, $m)) {
            return null;
        }

        $version = $m[1];
        $loader  = $m[2] ?? null;
        $uuid    = $server->uuid;

        // Cross-loader restore: the backup was a different loader than the
        // server's current egg. The restore brought back that loader's files but
        // left the egg unchanged, so nothing detects the restored loader. Swap
        // the egg config to match (DB-only — no wipe/reinstall). No-ops (returns
        // null) for a same-loader restore, which then falls through below.
        if ($loader) {
            try {
                $switched = $this->loaders->alignLoaderForRestore($server, strtolower($loader), $version);
                if ($switched !== null) {
                    return $switched;
                }
            } catch (\Throwable $e) {
                // fall through to version-only alignment
            }
        }

        // Same-loader restore: pin the version. Send both common version-variable
        // names — setServerVariables only writes the ones the egg actually has,
        // so this works whether the egg calls it MINECRAFT_VERSION or MC_VERSION.
        try {
            $this->ops->setServerVariables($uuid, [
                'MINECRAFT_VERSION' => $version,
                'MC_VERSION'        => $version,
                'BUILD_NUMBER'      => 'latest',
            ]);
        } catch (\Throwable $e) {
            // if we can't even pin the version, don't push further changes
            return null;
        }

        // Move the Docker image only if the Java requirement differs. Resolved
        // via preflight (validates + picks an image the egg allows).
        $imageChanged = false;
        try {
            $pf = $this->versions->preflight($server, $version, null);
            if (($pf['image']['change'] ?? false) && ! empty($pf['image']['resolved'])) {
                $this->ops->setDockerImage($uuid, $pf['image']['resolved']);
                $imageChanged = true;
            }
        } catch (\Throwable $e) {
            // leave the image as-is if a target image can't be resolved
        }

        // Keep the stored setting in step so the Mods tab compatibility filter
        // targets the restored version.
        try {
            \ServerSuite\Models\ModManagerSetting::forServer($uuid)->update([
                'game_version' => $version,
            ]);
        } catch (\Throwable $e) {
            // non-fatal
        }

        return ['version' => $version, 'loader' => $loader, 'image_changed' => $imageChanged];
    }

    /**
     * Nuclear reset: wipe files, then reinstall. The server must be offline
     * before files are touched, so this is a small state machine the caller
     * drives: if the server is still running it fires a stop and returns
     * {status: stopping}; the caller polls status and calls again once offline,
     * at which point the wipe + reinstall runs.
     *
     * @param string $mode 'keep_plugins' (reset world only) | 'clean_slate' (wipe all but eula.txt)
     */
    public function nuclear(object $server, string $mode): array
    {
        $uuid = $server->uuid;
        $mode = in_array($mode, ['keep_plugins', 'clean_slate'], true) ? $mode : 'keep_plugins';

        // The wipe is only recoverable if the reinstall after it can run (#3).
        ReinstallGuard::assertCanReinstall($server, 'reset the server');

        // Never touch files on a running server — stop first, let the caller poll.
        $state = $this->ops->runtimeState($uuid);
        if (($state['power'] ?? null) !== 'offline') {
            try {
                $this->ops->power($uuid, 'stop');
            } catch (\Throwable $e) {
                // if the stop can't be sent, surface it
                throw new \RuntimeException('Could not stop the server before wiping: ' . $e->getMessage());
            }

            return ['status' => 'stopping'];
        }

        // Offline: wipe per mode, then reinstall (regenerates jar/world at the
        // current version). eula.txt is preserved by the wipe so it still boots.
        $wiped = $this->files->wipeForNuclear($uuid, $mode);

        // Clean slate removes every mod file, so empty the manifest to match.
        if ($mode === 'clean_slate') {
            try {
                $this->manifest->clearAll($uuid);
            } catch (\Throwable $e) {
                // non-fatal
            }
        }

        $this->ops->reinstall($uuid);

        return [
            'status' => 'reinstalling',
            'mode'   => $mode,
            'wiped'  => $wiped,
        ];
    }

    /**
     * Is there a successful, completed backup recent enough to be this switch's
     * safety net? Defaults to the last hour (a pre-switch backup taken moments
     * ago by the caller).
     */
    public function hasRecentCompletedBackup(string $serverUuid, int $withinMinutes = 60): bool
    {
        $cutoff = now()->subMinutes($withinMinutes);

        foreach ($this->ops->listBackups($serverUuid) as $b) {
            if (! ($b->isSuccessful ?? false) || ($b->completedAt ?? null) === null) {
                continue;
            }
            try {
                if (\Illuminate\Support\Carbon::parse($b->completedAt)->greaterThanOrEqualTo($cutoff)) {
                    return true;
                }
            } catch (\Throwable $e) {
                // unparseable timestamp — ignore this backup
            }
        }

        return false;
    }
}
