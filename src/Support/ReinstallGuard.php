<?php

namespace ServerSuite\Support;

/**
 * Decides, before anything is touched, whether a server can be reinstalled.
 *
 * Version switches, loader switches and nuclear resets all end with a
 * reinstall: the egg's install script is what downloads the new server jar.
 * They stop the server, wipe files and rewrite variables FIRST, so if the
 * reinstall then can't happen the server is left wiped or half-switched.
 *
 * Two ways that happens:
 *  - "Skip Egg Install Script" (skip_scripts) is on. Wings never runs the
 *    install script, so no new jar is downloaded (any panel version).
 *  - Pterodactyl 1.15+ refuses the reinstall outright via
 *    Server::canBeReinstalled() (panel#5696) and throws.
 *
 * Callers must check this before stopping, wiping or changing anything.
 */
class ReinstallGuard
{
    /**
     * Why this server can't be reinstalled, or null if it can.
     */
    public static function blocker(object $server): ?string
    {
        if (! empty($server->skip_scripts)) {
            return 'This server has "Skip Egg Install Script" turned on, so a reinstall can\'t download '
                . 'the new server files (and Pterodactyl 1.15+ refuses the reinstall). Nothing was changed. '
                . 'An admin can turn it off in the server\'s Startup settings in the admin panel, then try again.';
        }

        if (method_exists($server, 'canBeReinstalled') && ! $server->canBeReinstalled()) {
            return 'The panel won\'t allow this server to be reinstalled right now. Nothing was changed.';
        }

        return null;
    }

    /**
     * @throws \RuntimeException with the blocker message, prefixed with $action.
     */
    public static function assertCanReinstall(object $server, string $action): void
    {
        $reason = self::blocker($server);

        if ($reason !== null) {
            throw new \RuntimeException("Cannot {$action}: {$reason}");
        }
    }
}
