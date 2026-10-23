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
        'players' => [
            'description' => 'Server Suite: the whitelist, operators and bans.',
            'keys' => [
                'read' => 'See the whitelist, operators, bans and who is online.',
                'manage' => 'Add and remove players on the whitelist, operators and bans, and turn the whitelist on or off.',
            ],
        ],
        'notifications' => [
            'description' => "Server Suite: this server's Discord and ntfy notifications.",
            'keys' => [
                'manage' => 'Add and remove notification targets (webhook URLs, tokens), choose events, and send a test.',
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
        'plan' => ['mods.install'], 'install' => ['mods.install'], 'installBatch' => ['mods.install'], 'removeBatch' => ['mods.remove'],
        'update' => ['mods.update'], 'updateAll' => ['mods.update'],
        'destroy' => ['mods.remove'],
        'reconcileAdopt' => ['mods.configure'], 'reconcileForget' => ['mods.configure'],
        // Backups: rotation deletes the oldest, so changing it needs delete too.
        'backups' => ['backup.read'],
        'backupsSaveSettings' => ['backup.create', 'backup.delete'], 'backupsRotate' => ['backup.create', 'backup.delete'],
        'backupsCreate' => ['backup.create'], 'backupsRename' => ['backup.create'],
        'backupsLock' => ['backup.delete'], 'backupsDelete' => ['backup.delete'],
        'backupsDownload' => ['backup.download'], 'backupsRestore' => ['backup.restore'],
        // After a restore (#60): the Mods tab's banner
        'restoreSync' => ['mods.read'], 'restoreSyncDismiss' => ['mods.read'],
        // Versions
        'serverVersions' => ['startup.read'], 'serverStatus' => ['startup.read'], 'loaders' => ['startup.read'],
        'serverVersionPreflight' => ['startup.read'],
        'serverVersionApply' => self::VERSION_CHANGE, 'loaderSwitch' => self::VERSION_CHANGE,
        'serverVersionNuclear' => ['settings.reinstall', 'file.delete'],
        // Backup before mod changes (#24): the clearance itself needs one of the
        // change permissions (checked in the controller); the change checks its own.
        'prechangeMode' => ['mods.read'], 'prechangeSetMode' => ['mods.install'],
        'prechange' => ['mods.read'], 'prechangeState' => ['mods.read'],
        // Crash help (#25)
        'crash' => ['mods.read'], 'crashDismiss' => ['mods.read'],
        'crashDisable' => ['mods.remove'], 'crashEnable' => ['mods.install'],
        // Bedrock crossplay (#28)
        'crossplay' => ['mods.read'], 'crossplayInstall' => ['mods.install'], 'crossplayConfigure' => ['mods.install'],
        // Velocity networks (#30): writes to every server in the network, so
        // only their owner (or a root admin): the 'owner' pseudo-permission.
        'network' => ['owner'], 'networkApply' => ['owner'],
        // Configs (#21): Pterodactyl's own file permissions, as its File Manager uses
        'configs' => ['file.read'], 'configsOpen' => ['file.read-content'], 'configsRender' => ['file.read-content'],
        'configsSave' => ['file.update'],
        // Properties (#20): server.properties is a server file. Saving with a
        // restart or a world reset also needs control.* / file.delete (checked
        // with extra()).
        'properties' => ['file.read-content'], 'propertiesSave' => ['file.update'],
        'propertiesResetWorld' => ['file.delete', 'control.start'],
        // Modpacks (#23). An install stops the server and replaces or removes
        // mod files, so it needs those permissions as well.
        'modpacks' => ['mods.read'], 'modpacksSearch' => ['mods.read'], 'modpackVersions' => ['mods.read'],
        'modpackPreview' => ['mods.read'], 'modpackInstall' => ['mods.install', 'mods.remove', 'control.stop'],
        // Players (#27)
        'players' => ['players.read'], 'playersAdd' => ['players.manage'], 'playersRemove' => ['players.manage'],
        'playersWhitelist' => ['players.manage'],
        // Notifications (#26)
        'notifications' => ['notifications.manage'], 'notificationsAdd' => ['notifications.manage'],
        'notificationsRemove' => ['notifications.manage'], 'notificationsEvents' => ['notifications.manage'],
        'notificationsTest' => ['notifications.manage'],
        // Subdomains
        'subdomains' => ['subdomains.read'],
        'subdomainsClaim' => ['subdomains.manage'], 'subdomainsRelease' => ['subdomains.manage'],
        'subdomainsRepoint' => ['subdomains.manage'], 'subdomainsStatusCard' => ['subdomains.manage'],
        'subdomainsSaveOwn' => ['subdomains.manage'], 'subdomainsRemoveOwn' => ['subdomains.manage'],
    ];

    /** Tab => permission pattern Pterodactyl's router checks (Components.yml carries the same). */
    public const TABS = ['Mods' => 'mods.*', 'Modpacks' => 'mods.*', 'Backups' => 'backup.*', 'Versions' => 'startup.*', 'Subdomains' => 'subdomains.*', 'Properties' => 'file.read-content', 'Players' => 'players.*', 'Notifications' => 'notifications.*', 'Velocity' => 'serversuite.network'];

    /** What a Properties save also needs, by what happens after it. */
    public const PROPERTIES_AFTER = [
        'none' => [], 'restart' => ['control.restart'], 'reset_world' => ['file.delete', 'control.stop', 'control.start'],
    ];

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
            if ($permission === 'owner' ? ! self::isOwner($user, $server) : ! $user->can($permission, $server)) {
                return false;
            }
        }

        return true;
    }

    /** The server's owner or a root admin (never a subuser, whatever they hold). */
    public static function isOwner(?object $user, object $server): bool
    {
        return $user !== null && ((bool) ($user->root_admin ?? false) || (int) ($server->owner_id ?? -1) === (int) ($user->id ?? -2));
    }

    /** Whether $user holds every one of $permissions on $server. @param list<string> $permissions */
    public static function holds(?object $user, object $server, array $permissions): bool
    {
        if ($user === null) {
            return false;
        }
        foreach ($permissions as $permission) {
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
