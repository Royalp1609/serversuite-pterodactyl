<?php

namespace ServerSuite\Support;

/**
 * Server states in which Server Suite does nothing on a server (#116), the
 * same ones Pterodactyl's client API refuses for everyone, root admins
 * included: the server is suspended, or its node is in maintenance.
 *
 * Installing, restoring and transfers are left alone on purpose: the
 * reinstall screen and the restore flow handle those themselves.
 */
class ServerState
{
    /** Why nothing can be done on this server right now, or null. */
    public static function blocker(object $server): ?string
    {
        if (method_exists($server, 'isSuspended') ? $server->isSuspended() : (($server->status ?? null) === 'suspended')) {
            return 'This server is currently suspended and the functionality requested is unavailable.';
        }

        $node = $server->node ?? null;
        if (is_object($node) && (method_exists($node, 'isUnderMaintenance') ? $node->isUnderMaintenance() : (bool) ($node->maintenance_mode ?? false))) {
            return 'The node of this server is currently under maintenance and the functionality requested is unavailable.';
        }

        return null;
    }
}
