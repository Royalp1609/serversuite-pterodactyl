<?php

namespace ServerSuite\Support;

/**
 * Which build loaders a server's loader can run. A Purpur server runs Paper,
 * Spigot and Bukkit plugins, a Quilt server runs Fabric mods, and so on.
 * Repositories tag builds with loaders, and an exact match missed these.
 */
final class LoaderCompat
{
    /** server loader => build loaders it runs, best first */
    private const RUNS = [
        'paper'      => ['paper', 'spigot', 'bukkit'],
        'purpur'     => ['purpur', 'paper', 'spigot', 'bukkit'],
        'pufferfish' => ['pufferfish', 'paper', 'spigot', 'bukkit'],
        'folia'      => ['folia'],   // only plugins that say they support Folia
        'spigot'     => ['spigot', 'bukkit'],
        'bukkit'     => ['bukkit'],
        'fabric'     => ['fabric'],
        'quilt'      => ['quilt', 'fabric'],
        'forge'      => ['forge'],
        'neoforge'   => ['neoforge'],
        'velocity'   => ['velocity'],
        'waterfall'  => ['waterfall', 'bungeecord'],
        'bungeecord' => ['bungeecord'],
        'sponge'     => ['sponge'],
    ];

    /** @return string[] the build loaders this server runs; [] when the server's loader is unknown */
    public static function accepts(?string $serverLoader): array
    {
        $l = strtolower(trim((string) $serverLoader));

        return $l === '' ? [] : (self::RUNS[$l] ?? [$l]);
    }

    /**
     * Whether a build tagged with $buildLoaders runs on this server. An
     * unknown server loader or an untagged build is given the benefit of the
     * doubt.
     *
     * @param string[] $buildLoaders
     */
    public static function runs(?string $serverLoader, array $buildLoaders): bool
    {
        $accepts = self::accepts($serverLoader);
        $tags = array_map(fn ($t) => strtolower((string) $t), $buildLoaders);

        return $accepts === [] || $tags === [] || array_intersect($accepts, $tags) !== [];
    }
}
