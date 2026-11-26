<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Models\Modpack;
use ServerSuite\Support\SuiteActivity;

/**
 * Start a server again after a reinstall or a backup restore if it was
 * running before (#69). Neither Pterodactyl nor Wings does: the server stays
 * stopped, however the reinstall or restore was started.
 *
 * remember() runs from the Server model's updated event the moment the
 * status becomes installing or restoring_backup: inside the panel's own
 * request, before it tells Wings to reinstall or restore (which stops the
 * server), so the power state read then is the one from before. resume()
 * runs when it finishes (SyncAfterInstallJob, SyncAfterRestoreJob).
 *
 * A loader switch, a version change that wipes files and a server reset
 * stop the server themselves before the reinstall (files can only be wiped
 * while it's stopped), so by the time the status changes it reads offline.
 * They call stoppedForSwitch() first, and remember() counts that as "it was
 * on".
 *
 * A modpack install is left alone: its own loader switch reinstalls the
 * server, which has to stay stopped until the pack is done; the installer
 * starts it at the end instead (ModpackInstaller).
 */
class AutoStartService
{
    /** Power states that mean "it was on". */
    private const ON = ['running', 'starting', 'stopping'];

    /** A reinstall or restore that never finishes doesn't start the server days later. */
    private const TTL_HOURS = 6;

    /** How long a switch's own stop counts: it takes a backup and waits for the stop first. */
    private const SWITCH_TTL_MINUTES = 30;

    public function __construct(protected PanelServerOps $ops) {}

    /** The status just became installing or restoring_backup. Never throws. */
    public function remember(string $uuid): void
    {
        Cache::forget($this->key($uuid));
        $stoppedForSwitch = Cache::pull($this->switchKey($uuid)) !== null;
        try {
            if (Modpack::forServer($uuid)?->isRunning()) {
                return;
            }
            $wasOn = $stoppedForSwitch || in_array($this->ops->runtimeState($uuid)['power'] ?? null, self::ON, true);
        } catch (\Throwable) {
            $wasOn = $stoppedForSwitch;   // Wings unreachable: only what the switch saw
        }
        if ($wasOn) {
            Cache::put($this->key($uuid), now()->getTimestamp(), now()->addHours(self::TTL_HOURS));
        }
    }

    /**
     * A switch is about to stop this server to wipe files before its
     * reinstall: the server is on now, so start it again afterwards.
     * Never throws.
     */
    public function stoppedForSwitch(string $uuid): void
    {
        try {
            Cache::put($this->switchKey($uuid), now()->getTimestamp(), now()->addMinutes(self::SWITCH_TTL_MINUTES));
        } catch (\Throwable) {
            // no cache: the server stays stopped, as before
        }
    }

    /** It failed: leave the server as it is. */
    public function forget(string $uuid): void
    {
        Cache::forget($this->key($uuid));
        Cache::forget($this->switchKey($uuid));
    }

    /**
     * The reinstall or restore finished. Starts the server when it was
     * running before and is offline now. Never throws.
     *
     * @param string $after reinstall | restore
     */
    public function resume(string $uuid, string $after): bool
    {
        if (Cache::pull($this->key($uuid)) === null) {
            return false;
        }
        try {
            if (Modpack::forServer($uuid)?->isRunning()) {
                return false;
            }
            if (($this->ops->runtimeState($uuid)['power'] ?? null) !== 'offline') {
                return false;   // already started (the Versions page starts its own)
            }
            $this->ops->power($uuid, 'start');
            SuiteActivity::logFor($uuid, 'server:serversuite.auto-started', ['after' => $after]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function switchKey(string $uuid): string
    {
        return "serversuite.autostart.switch.{$uuid}";
    }

    private function key(string $uuid): string
    {
        return "serversuite.autostart.{$uuid}";
    }
}
