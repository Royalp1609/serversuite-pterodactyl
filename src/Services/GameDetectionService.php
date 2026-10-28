<?php

namespace ServerSuite\Services;

use ServerSuite\Models\ModManagerGlobalSetting;
use ServerSuite\Support\Data\GameProfile;

/**
 * Works out which game a server runs (#81), so tabs and actions that assume
 * Minecraft stay off other games.
 *
 * Order:
 *   1. an admin's choice for the server's egg (Server Suite admin page)
 *   2. the Steam app id in SRCDS_APPID, matched against config('serversuite.games')
 *   3. Minecraft: the egg's nest is named Minecraft, or the egg alone names a loader
 *      (uuid, name, image, startup or install script; see LoaderDetectionService),
 *      or Server Suite already stored a loader for the server
 *   4. otherwise "unknown": game-neutral features only
 *
 * The Steam app id is checked before the Minecraft keywords because a Rust
 * egg's install script mentions "vanilla" and similar words.
 *
 * Reads only the database (no Wings calls), and is memoised per
 * request, since the navigation asks once per tab on every render.
 */
class GameDetectionService
{
    /** ModManagerGlobalSetting key: JSON {egg id: game key} set by an admin. */
    public const OVERRIDES = 'game_overrides';

    /** @var array<string, GameProfile> */
    private static array $memo = [];

    public function __construct(protected LoaderDetectionService $loaders) {}

    public function for(object $server): GameProfile
    {
        $key = ($server->uuid ?? spl_object_id($server)) . ':' . ($server->egg_id ?? '');

        return self::$memo[$key] ??= $this->detect($server);
    }

    /**
     * @param bool $withOverride false = ignore the admin's choice (the settings
     *        page shows what detection alone would say)
     */
    public function detect(object $server, bool $withOverride = true): GameProfile
    {
        $games = (array) config('serversuite.games', []);

        $override = $withOverride ? (self::overrides()[(int) ($server->egg_id ?? 0)] ?? null) : null;
        if ($override === 'minecraft') {
            return GameProfile::minecraft('admin');
        }
        if ($override === 'unknown') {
            return new GameProfile('unknown', 'Unknown game', GameProfile::NEUTRAL, signal: 'admin');
        }
        if (is_string($override) && isset($games[$override])) {
            return GameProfile::fromConfig($override, (array) $games[$override], 'admin');
        }

        $appId = trim((string) $this->loaders->serverVariable($server, ['SRCDS_APPID']));
        if ($appId !== '') {
            foreach ($games as $game => $config) {
                if (in_array((int) $appId, array_map('intval', (array) ($config['steam_app_ids'] ?? [])), true)) {
                    return GameProfile::fromConfig((string) $game, (array) $config, 'steam_app_id');
                }
            }
        }

        if ($this->minecraftNest($server)) {
            return GameProfile::minecraft('nest');
        }

        $loader = $this->loaders->eggLoader($server);
        if ($loader->isResolved()) {
            return GameProfile::minecraft($loader->signal);
        }

        // A loader Server Suite already stored (detected earlier, or set by
        // the owner) means the server has been running as Minecraft.
        if (isset($server->uuid) && \ServerSuite\Models\ModManagerSetting::query()
            ->where('server_uuid', (string) $server->uuid)->whereNotNull('loader')->where('loader', '!=', '')->exists()) {
            return GameProfile::minecraft('stored_loader');
        }

        return GameProfile::unknown();
    }

    /**
     * What detection says for servers on $egg with the egg's default
     * variables, ignoring the admin's choice.
     */
    public function forEgg(object $egg): GameProfile
    {
        $variables = method_exists($egg, 'variables') ? $egg->variables()->get() : ($egg->variables ?? []);

        return $this->detect((object) ['egg' => $egg, 'egg_id' => $egg->id ?? 0, 'variables' => $variables], false);
    }

    /**
     * The game-specific egg variable for $setting (e.g. "hostname"), using
     * the aliases in config('serversuite.games.<game>.variables').
     */
    public function variable(object $server, string $setting): ?string
    {
        $profile = $this->for($server);
        $names = (array) config("serversuite.games.{$profile->key}.variables.{$setting}", []);

        return $names === [] ? null : $this->loaders->serverVariable($server, $names);
    }

    /**
     * Game keys an admin can pick, with names.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $out = ['minecraft' => 'Minecraft'];
        foreach ((array) config('serversuite.games', []) as $key => $config) {
            $out[(string) $key] = (string) ($config['name'] ?? ucfirst((string) $key));
        }

        return $out + ['unknown' => 'Other (game-neutral tabs only)'];
    }

    /** @return array<int, string> egg id => game key */
    public static function overrides(): array
    {
        $raw = json_decode((string) ModManagerGlobalSetting::getValue(self::OVERRIDES, '{}'), true);

        $out = [];
        foreach (is_array($raw) ? $raw : [] as $egg => $game) {
            if ((int) $egg > 0 && is_string($game)) {
                $out[(int) $egg] = $game;
            }
        }

        return $out;
    }

    /** Set ($game) or clear (null) the admin's choice for an egg. */
    public static function setOverride(int $eggId, ?string $game): void
    {
        if ($game !== null && ! array_key_exists($game, self::choices())) {
            throw new \InvalidArgumentException("Unknown game \"{$game}\".");
        }

        $all = self::overrides();
        if ($game === null) {
            unset($all[$eggId]);
        } else {
            $all[$eggId] = $game;
        }

        ModManagerGlobalSetting::setValue(self::OVERRIDES, json_encode((object) $all));
        self::flush();
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    /** Pterodactyl eggs have no tags; their nest says what they are ("Minecraft" on a stock panel). */
    protected function minecraftNest(object $server): bool
    {
        $egg = $server->egg ?? null;
        $nest = is_object($egg) ? ($egg->nest ?? null) : null;

        return is_object($nest) && str_contains(strtolower((string) ($nest->name ?? '')), 'minecraft');
    }
}
