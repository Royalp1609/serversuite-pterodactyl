<?php

namespace ServerSuite\Support\Data;

/**
 * Which game a server runs, and which parts of Server Suite apply to it (#81).
 *
 * Minecraft keeps every feature it had. Other games get the game-neutral
 * features (dashboard, backups, notifications, subdomains) plus whatever their
 * profile in config('serversuite.games') lists; list a feature there only once
 * it works for that game. "unknown" is a server we
 * couldn't identify: game-neutral features only, nothing that assumes
 * Minecraft files or could turn the server into Minecraft.
 */
readonly class GameProfile
{
    /** Features every game gets: none of them read or write game files. */
    public const NEUTRAL = ['dashboard', 'backups', 'notifications', 'subdomains'];

    /** Minecraft-only parts: tabs, and actions that reinstall into a Minecraft egg. */
    public const MINECRAFT = ['mods', 'modpacks', 'versions', 'properties', 'players', 'errors', 'network', 'crossplay', 'reset'];

    /**
     * @param list<string> $features
     */
    public function __construct(
        public string $key,
        public string $name,
        public array $features,
        public ?string $srvService = null,
        public string $modsLabel = 'Mods',
        public string $signal = 'none',
    ) {}

    public static function minecraft(string $signal = 'minecraft'): self
    {
        return new self('minecraft', 'Minecraft', [...self::NEUTRAL, ...self::MINECRAFT], '_minecraft._tcp', 'Mods', $signal);
    }

    public static function unknown(): self
    {
        return new self('unknown', 'Unknown game', self::NEUTRAL);
    }

    /**
     * A profile from a config('serversuite.games') entry.
     *
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $key, array $config, string $signal): self
    {
        return new self(
            $key,
            (string) ($config['name'] ?? ucfirst($key)),
            array_values(array_unique([...self::NEUTRAL, ...array_map('strval', (array) ($config['features'] ?? []))])),
            isset($config['srv']) && $config['srv'] !== '' ? (string) $config['srv'] : null,
            (string) ($config['mods_label'] ?? 'Mods'),
            $signal,
        );
    }

    public function isMinecraft(): bool
    {
        return $this->key === 'minecraft';
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
