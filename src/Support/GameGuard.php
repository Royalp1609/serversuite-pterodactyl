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
     * Client endpoint (controller method) => the game feature it belongs to
     * (or features: any one of them will do).
     * Endpoints not listed (backups, notifications, subdomains, the backup
     * prompt, crash and restore banners) work on every game.
     */
    public const ENDPOINT_FEATURES = [
        'gameVersions' => 'mods', 'details' => 'mods', 'installed' => 'mods', 'repositories' => 'mods', 'search' => 'mods',
        'versions' => 'mods', 'tasks' => 'mods', 'reconcile' => 'mods', 'plan' => 'mods', 'install' => 'mods',
        'installBatch' => 'mods', 'removeBatch' => 'mods', 'update' => 'mods', 'updateAll' => 'mods', 'destroy' => 'mods',
        'reconcileAdopt' => 'mods', 'reconcileForget' => 'mods',
        'crashDisable' => 'mods', 'crashEnable' => 'mods',
        // Minecraft edits configs in the Mods tab, Rust and Valheim in their Configs tab (#86).
        'configs' => ['mods', 'configs'], 'configsOpen' => ['mods', 'configs'], 'configsRender' => ['mods', 'configs'], 'configsSave' => ['mods', 'configs'],
        'crossplay' => 'crossplay', 'crossplayInstall' => 'crossplay', 'crossplayConfigure' => 'crossplay',
        'autoUpgradeState' => 'versions', 'autoUpgradeSave' => 'versions',
        'serverVersions' => 'versions', 'loaders' => 'versions', 'loaderVersions' => 'versions', 'serverVersionPreflight' => 'versions',
        'serverVersionApply' => 'versions', 'loaderSwitch' => 'versions', 'serverVersionNuclear' => 'reset',
        'network' => 'network', 'networkApply' => 'network',
        'properties' => 'properties', 'propertiesSave' => 'properties', 'propertiesResetWorld' => 'properties',
        'modpacks' => 'modpacks', 'modpacksSearch' => 'modpacks', 'modpackVersions' => 'modpacks',
        'modpackPreview' => 'modpacks', 'modpackInstall' => 'modpacks', 'modpackRemove' => 'modpacks',
        'errors' => 'errors', 'errorsCrash' => 'errors', 'errorsAcknowledge' => 'errors',
        // Bedrock add-ons (#93): Bedrock counts as Minecraft; the endpoints also check it's BDS.
        'addons' => 'addons', 'addonsFolder' => 'addons', 'addonsInstall' => 'addons', 'addonsRemove' => 'addons',
        'addonsEnable' => 'addons', 'addonsMove' => 'addons', 'addonsTexturepack' => 'addons',
        'players' => 'players', 'playersAdd' => 'players', 'playersRemove' => 'players', 'playersWhitelist' => 'players',
    ];

    /** Minecraft features that are Java Edition only: hidden and refused on Bedrock Dedicated Server. */
    public const JAVA_ONLY = ['mods', 'modpacks', 'versions', 'reset', 'players', 'errors', 'network', 'crossplay'];

    /**
     * Whether $server's game has $feature. Bedrock Dedicated Server counts as
     * Minecraft but has none of the JAVA_ONLY features; "addons" (#93) is
     * Bedrock Dedicated Server's alone.
     */
    public static function supports(object $server, string $feature): bool
    {
        $bedrock = \ServerSuite\Services\BedrockAddonService::isBedrock($server);
        if ($feature === 'addons') {
            return $bedrock;
        }
        if ($bedrock && in_array($feature, self::JAVA_ONLY, true)) {
            return false;
        }

        return app(GameDetectionService::class)->for($server)->supports($feature);
    }

    /** The features $server has, for the sidebar (GET /game). @return list<string> */
    public static function features(object $server): array
    {
        $all = [...app(GameDetectionService::class)->for($server)->features, 'addons'];

        return array_values(array_unique(array_filter($all, fn ($f) => self::supports($server, $f))));
    }

    /**
     * Why $endpoint isn't available on $server's game, or null if it is.
     */
    public static function endpointBlocker(object $server, string $endpoint): ?string
    {
        $features = (array) (self::ENDPOINT_FEATURES[$endpoint] ?? []);
        if ($features === []) {
            return null;
        }

        $game = app(GameDetectionService::class)->for($server);
        foreach ($features as $feature) {
            if (self::supports($server, $feature)) {
                return null;
            }
        }
        if (\ServerSuite\Services\BedrockAddonService::isBedrock($server)) {
            return in_array('addons', $features, true)
                ? 'Add-ons are for Bedrock Dedicated Server.'
                : 'This only works on Minecraft Java Edition, and this is a Bedrock Dedicated Server.';
        }
        if (in_array('addons', $features, true)) {
            return 'Add-ons are for Bedrock Dedicated Server.';
        }

        return ($game->key === 'unknown'
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
        if (\ServerSuite\Services\BedrockAddonService::isBedrock($server)) {
            throw new \RuntimeException("Cannot {$action}: this only works on Minecraft Java Edition, and this is a Bedrock Dedicated Server. Nothing was changed.");
        }
    }
}
