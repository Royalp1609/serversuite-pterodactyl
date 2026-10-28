<?php

namespace ServerSuite\Support;

use ServerSuite\Services\GameDetectionService;

/**
 * Refuses Minecraft-only actions on servers that aren't Minecraft (#82).
 *
 * Version and loader switches, resets and modpack installs wipe a server and
 * reinstall it into a Minecraft egg. On a Rust, Valheim or unidentified
 * server that would turn a plan sold as one game into a Minecraft server, so
 * the services check here before touching anything, whatever the page shows.
 */
class GameGuard
{
    /**
     * Client endpoint (controller method) => the game feature it belongs to.
     * Endpoints not listed (backups, notifications, subdomains, the backup
     * prompt, crash and restore banners) work on every game.
     */
    public const ENDPOINT_FEATURES = [
        'gameVersions' => 'mods', 'details' => 'mods', 'installed' => 'mods', 'repositories' => 'mods', 'search' => 'mods',
        'versions' => 'mods', 'tasks' => 'mods', 'reconcile' => 'mods', 'plan' => 'mods', 'install' => 'mods',
        'installBatch' => 'mods', 'removeBatch' => 'mods', 'update' => 'mods', 'updateAll' => 'mods', 'destroy' => 'mods',
        'reconcileAdopt' => 'mods', 'reconcileForget' => 'mods',
        'crashDisable' => 'mods', 'crashEnable' => 'mods',
        'configs' => 'mods', 'configsOpen' => 'mods', 'configsRender' => 'mods', 'configsSave' => 'mods',
        'crossplay' => 'crossplay', 'crossplayInstall' => 'crossplay', 'crossplayConfigure' => 'crossplay',
        'serverVersions' => 'versions', 'loaders' => 'versions', 'serverVersionPreflight' => 'versions',
        'serverVersionApply' => 'versions', 'loaderSwitch' => 'versions', 'serverVersionNuclear' => 'reset',
        'network' => 'network', 'networkApply' => 'network',
        'properties' => 'properties', 'propertiesSave' => 'properties', 'propertiesResetWorld' => 'properties',
        'modpacks' => 'modpacks', 'modpacksSearch' => 'modpacks', 'modpackVersions' => 'modpacks',
        'modpackPreview' => 'modpacks', 'modpackInstall' => 'modpacks', 'modpackRemove' => 'modpacks',
        'players' => 'players', 'playersAdd' => 'players', 'playersRemove' => 'players', 'playersWhitelist' => 'players',
    ];

    /**
     * Why $endpoint isn't available on $server's game, or null if it is.
     */
    public static function endpointBlocker(object $server, string $endpoint): ?string
    {
        $feature = self::ENDPOINT_FEATURES[$endpoint] ?? null;
        if ($feature === null) {
            return null;
        }

        $game = app(GameDetectionService::class)->for($server);

        return $game->supports($feature) ? null : ($game->key === 'unknown'
            ? 'This only works on Minecraft servers.'
            : "This only works on Minecraft servers, and this one runs {$game->name}.");
    }

    /**
     * @throws \RuntimeException
     */
    public static function assertMinecraft(object $server, string $action): void
    {
        $game = app(GameDetectionService::class)->for($server);

        if (! $game->isMinecraft()) {
            $what = $game->key === 'unknown' ? 'a Minecraft server' : "a Minecraft server (this one runs {$game->name})";

            throw new \RuntimeException("Cannot {$action}: this only works on {$what}. Nothing was changed.");
        }
    }
}
