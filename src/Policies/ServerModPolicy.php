<?php

namespace ServerSuite\Policies;

use ServerSuite\Models\ServerMod;

/**
 * Gates for every mod-manager action.
 *
 * The $server parameter is the Pterodactyl/Pelican Server model instance.
 * We avoid a hard type-hint so the policy works on both panels without
 * importing their specific model class.
 *
 * Root admins pass all gates unconditionally via the before() hook.
 */
class ServerModPolicy
{
    /**
     * Root admins bypass all checks.
     */
    public function before(mixed $user, mixed $ability): ?bool
    {
        if (method_exists($user, 'isRootAdmin') && $user->isRootAdmin()) {
            return true;
        }

        return null; // fall through to individual methods
    }

    /**
     * Can the user see the mod manager for this server?
     */
    public function view(mixed $user, mixed $server): bool
    {
        return $this->hasPermission($user, $server, config('serversuite.permissions.view'));
    }

    /**
     * Can the user install mods on this server?
     */
    public function install(mixed $user, mixed $server): bool
    {
        return $this->hasPermission($user, $server, config('serversuite.permissions.install'));
    }

    /**
     * Can the user update mods on this server?
     */
    public function update(mixed $user, mixed $server): bool
    {
        return $this->hasPermission($user, $server, config('serversuite.permissions.update'));
    }

    /**
     * Can the user remove mods from this server?
     */
    public function remove(mixed $user, mixed $server): bool
    {
        return $this->hasPermission($user, $server, config('serversuite.permissions.remove'));
    }

    /**
     * Can the user change mod manager settings for this server?
     * (e.g. enable/disable repos, change install path)
     */
    public function manage(mixed $user, mixed $server): bool
    {
        return $this->hasPermission($user, $server, config('serversuite.permissions.manage'));
    }

    // -------------------------------------------------------------------------

    /**
     * Delegates to the panel's own sub-user permission system.
     * If the panel uses a different permission model, swap the implementation here.
     */
    protected function hasPermission(mixed $user, mixed $server, string $node): bool
    {
        // Server owner always has all permissions
        if (isset($server->owner_id) && (int) $user->id === (int) $server->owner_id) {
            return true;
        }

        return in_array($node, $this->subuserPermissions($user, $server), true);
    }

    /**
     * Resolve the permission node strings granted to this user on this server.
     *
     * In both Pterodactyl and Pelican, sub-user permissions are stored on the
     * Subuser pivot record (a `permissions` array column), NOT as a flat
     * relation on the User. We locate the subuser record for this user+server
     * and read its permissions array.
     *
     * If your panel exposes this differently, this is the single method to swap.
     *
     * @return string[]
     */
    protected function subuserPermissions(mixed $user, mixed $server): array
    {
        // Preferred: the server exposes its subusers relation
        if (method_exists($server, 'subusers')) {
            $subuser = $server->subusers()
                ->where('user_id', $user->id)
                ->first();

            if ($subuser && isset($subuser->permissions)) {
                return is_array($subuser->permissions)
                    ? $subuser->permissions
                    : (array) json_decode($subuser->permissions, true);
            }

            return [];
        }

        // Fallback: the user exposes a subuserOf / accessibleServers style relation
        if (method_exists($user, 'subuserOf')) {
            $subuser = $user->subuserOf()
                ->where('server_id', $server->id)
                ->first();

            if ($subuser && isset($subuser->permissions)) {
                return is_array($subuser->permissions)
                    ? $subuser->permissions
                    : (array) json_decode($subuser->permissions, true);
            }
        }

        return [];
    }
}
