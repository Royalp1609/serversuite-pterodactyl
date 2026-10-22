<?php

namespace ServerSuite\Support;

/**
 * Server Suite on Pterodactyl's own subuser permissions (#17).
 *
 * Every client endpoint maps to the permissions it needs (all of them), and
 * the check is Pterodactyl's: $user->can('<group>.<key>', $server) through
 * its ServerPolicy, so root admins and owners have everything and subusers
 * exactly what they were granted. Built-in permissions are reused where they
 * mean the same thing (backup.*, startup.*, settings.reinstall); the two new
 * groups, mods and subdomains, are added to Pterodactyl's permission list so
 * they show on its subuser screen and survive its permission filter.
 */
final class PanelPermissions
{
    private const PANEL_PERMISSION = '\\Pterodactyl\\Models\\Permission';

    /** Groups added to Pterodactyl's list. Names match the Pelican plugin's where they can. */
    public const GROUPS = [
        'mods' => [
            'description' => 'Server Suite: browse, install, update and remove mods and plugins.',
            'keys' => [
                'read' => 'See installed mods and browse the mod catalog.',
                'install' => 'Install new mods and plugins.',
                'update' => 'Update installed mods to newer versions.',
                'remove' => 'Remove installed mods.',
                'configure' => 'Adopt or forget mod files found on the server.',
            ],
        ],
        'subdomains' => [
            'description' => "Server Suite: this server's subdomains.",
            'keys' => [
                'read' => "See the server's subdomains.",
                'manage' => 'Claim, release and re-point subdomains, and set up an own domain (including its Cloudflare token).',
            ],
        ],
    ];

    private const VERSION_CHANGE = ['startup.update', 'startup.docker-image', 'settings.reinstall', 'backup.create'];

    /** Client endpoint (controller method) => permissions it needs. */
    public const ENDPOINTS = [
        // Mods
        'gameVersions' => ['mods.read'], 'details' => ['mods.read'], 'installed' => ['mods.read'],
        'repositories' => ['mods.read'], 'search' => ['mods.read'], 'versions' => ['mods.read'],
        'tasks' => ['mods.read'], 'reconcile' => ['mods.read'],
        'plan' => ['mods.install'], 'install' => ['mods.install'],
        'update' => ['mods.update'], 'updateAll' => ['mods.update'],
        'destroy' => ['mods.remove'],
        'reconcileAdopt' => ['mods.configure'], 'reconcileForget' => ['mods.configure'],
        // Backups: rotation deletes the oldest, so changing it needs delete too.
        'backups' => ['backup.read'],
        'backupsSaveSettings' => ['backup.create', 'backup.delete'], 'backupsRotate' => ['backup.create', 'backup.delete'],
        'backupsCreate' => ['backup.create'], 'backupsRename' => ['backup.create'],
        'backupsLock' => ['backup.delete'], 'backupsDelete' => ['backup.delete'],
        'backupsDownload' => ['backup.download'], 'backupsRestore' => ['backup.restore'],
        // Versions
        'serverVersions' => ['startup.read'], 'serverStatus' => ['startup.read'], 'loaders' => ['startup.read'],
        'serverVersionPreflight' => ['startup.read'],
        'serverVersionApply' => self::VERSION_CHANGE, 'loaderSwitch' => self::VERSION_CHANGE,
        'serverVersionNuclear' => ['settings.reinstall', 'file.delete'],
        // Configs (#21): Pterodactyl's own file permissions, as its File Manager uses
        'configs' => ['file.read'], 'configsOpen' => ['file.read-content'], 'configsRender' => ['file.read-content'],
        'configsSave' => ['file.update'],
        // Subdomains
        'subdomains' => ['subdomains.read'],
        'subdomainsClaim' => ['subdomains.manage'], 'subdomainsRelease' => ['subdomains.manage'],
        'subdomainsRepoint' => ['subdomains.manage'], 'subdomainsStatusCard' => ['subdomains.manage'],
        'subdomainsSaveOwn' => ['subdomains.manage'], 'subdomainsRemoveOwn' => ['subdomains.manage'],
    ];

    /** Tab => permission pattern Pterodactyl's router checks (Components.yml carries the same). */
    public const TABS = ['Mods' => 'mods.*', 'Backups' => 'backup.*', 'Versions' => 'startup.*', 'Subdomains' => 'subdomains.*'];

    /** @return list<string> */
    public static function required(string $endpoint): array
    {
        return self::ENDPOINTS[$endpoint] ?? throw new \LogicException("No permissions defined for {$endpoint}.");
    }

    /** Whether $user may call $endpoint on $server. An unknown endpoint is a no. */
    public static function allows(?object $user, object $server, string $endpoint): bool
    {
        if ($user === null || ! isset(self::ENDPOINTS[$endpoint])) {
            return false;
        }
        foreach (self::ENDPOINTS[$endpoint] as $permission) {
            if (! $user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Add the groups to Pterodactyl's permission list. It's a protected
     * static array with no API, so this uses reflection; if it's missing or
     * has changed shape it logs and does nothing (built-in permissions still
     * work, and CI catches the change on each panel version).
     */
    public static function register(): bool
    {
        $class = self::PANEL_PERMISSION;
        if (! class_exists($class)) {
            return false;
        }
        try {
            $prop = new \ReflectionProperty($class, 'permissions');
            if (! $prop->isStatic()) {
                throw new \RuntimeException('Permission::$permissions is no longer static');
            }
            $list = $prop->getValue();
            if (! is_array($list)) {
                throw new \RuntimeException('Permission::$permissions is no longer an array');
            }
            foreach (self::GROUPS as $group => $def) {
                $list[$group] ??= $def;
            }
            $prop->setValue(null, $list);

            return true;
        } catch (\Throwable $e) {
            logger()->warning('[Server Suite] could not add permission groups to the panel', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
