<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\ServerMod;

/**
 * Bedrock crossplay in one click (#28): Geyser (Bedrock players can join)
 * and Floodgate (no Java account needed).
 *
 *  - Paper / Purpur / Spigot: both from GeyserMC's downloads (Spigot build).
 *  - Fabric / NeoForge: both from Modrinth, so Fabric API etc. come along.
 *  - Folia and proxies: not offered.
 *
 * Connections (configure(), after Geyser's first start writes config.yml):
 * from 27 Oct 2026 (Bedrock 26.60) clients only connect over NetherNet, so
 * Geyser is set to transport "nethernet" on the Java port (UDP; Wings maps
 * every allocation for TCP and UDP) with external signaling ("nxs", HTTPS,
 * run by GeyserMC), because built-in signaling would need the Java port's
 * TCP. With a second allocation it uses "both" (RakNet on the Java port,
 * NetherNet on the second) so older clients still get in.
 * Paper-family servers also get ViaVersion: Geyser follows the newest
 * Minecraft, and older servers need it to translate.
 */
class CrossplayService
{
    private const PLUGIN_LOADERS = ['paper', 'purpur', 'spigot', 'bukkit'];
    private const MOD_LOADERS = ['fabric', 'neoforge'];

    /** [source, project] per component and loader family. */
    private const SOURCES = [
        'plugin' => ['geyser' => ['geysermc', 'geyser'], 'floodgate' => ['geysermc', 'floodgate'], 'viaversion' => ['modrinth', 'P1OZGk5p']],
        'mod'    => ['geyser' => ['modrinth', 'wKkoqHrH'], 'floodgate' => ['modrinth', 'bWrNNfkb']],
    ];

    public function __construct(
        protected DaemonFileClient $daemon,
        protected ModManagerService $mods,
    ) {}

    /** @return array<string, mixed> */
    public function status(object $server): array
    {
        $loader = strtolower((string) (ModManagerSetting::forServer($server->uuid)->loader ?: ''));
        $family = in_array($loader, self::PLUGIN_LOADERS, true) ? 'plugin' : (in_array($loader, self::MOD_LOADERS, true) ? 'mod' : null);

        $reason = match (true) {
            $family !== null => null,
            $loader === 'folia' => 'Geyser has no Folia build yet.',
            in_array($loader, ['velocity', 'waterfall', 'bungeecord'], true) => 'On a proxy, add Geyser to the proxy for the whole network (not handled here yet).',
            default => 'Bedrock crossplay needs Paper, Purpur, Spigot, Fabric or NeoForge.',
        };

        $installed = [];
        foreach (array_keys(self::SOURCES[$family ?? 'plugin']) as $part) {
            $installed[$part] = $family ? $this->installedPart($server->uuid, $part) !== null : false;
        }

        $alloc = $server->allocation ?? null;
        $javaPort = $alloc ? (int) $alloc->port : null;
        $host = null;
        $host = \ServerSuite\Models\Subdomain::query()->where('server_uuid', $server->uuid)->orderBy('id')->value('fqdn');
        $host ??= $alloc ? (($alloc->ip_alias ?? null) ?: ($alloc->ip ?? null)) : null;
        $ports = [];
        foreach (($server->allocations ?? []) as $a) {
            $ports[] = (int) $a->port;
        }

        $config = $family ? $this->configPath($loader) : null;
        $bedrockPort = $webrtcPort = $transport = $signaling = null;
        if ($config && $installed['geyser']) {
            $yaml = $this->daemon->readFile($server->uuid, $config, 1048576);
            if ($yaml !== null) {
                $bedrockPort = self::bedrockPort($yaml);
                $transport = self::bedrockValue($yaml, 'transport') ?? 'raknet';
                $webrtcPort = (int) (self::bedrockValue($yaml, 'webrtc-port') ?? 0) ?: $bedrockPort;
                $signaling = self::signalingMode($yaml);
            }
        }
        // External signaling registers with GeyserMC and prints the address
        // players join with ("Players can join at https://xyz.wdn.gg").
        $joinAddress = null;
        if ($signaling === 'nxs' && in_array($transport, ['nethernet', 'both'], true)) {
            $log = (string) $this->daemon->readFile($server->uuid, '/logs/latest.log', 8 * 1048576);
            if (preg_match_all('/Players can join at (\S+)/', $log, $m)) {
                $joinAddress = rtrim((string) end($m[1]), '.,;)');
            }
        }
        $known = $ports ?: array_filter([$javaPort]);
        $nethernet = in_array($transport, ['nethernet', 'both'], true);
        $reachable = $bedrockPort !== null && in_array($bedrockPort, $known, true)
            && (! $nethernet || in_array($webrtcPort, $known, true));

        return [
            'supported'    => $family !== null,
            'reason'       => $reason,
            'loader'       => $loader,
            'installed'    => $installed,
            'config'       => $config,
            'config_found' => $bedrockPort !== null,
            'bedrock_port' => $bedrockPort,
            'java_port'    => $javaPort,
            'webrtc_port'  => $webrtcPort,
            'transport'    => $transport,
            'signaling'    => $signaling,
            'nethernet'    => $nethernet,
            'reachable'    => $reachable,
            'ready'        => $reachable && $nethernet,
            'second_port'  => collect($known)->first(fn ($p) => $p !== $javaPort),
            'address'      => $host,
            'join_address' => $joinAddress,
        ];
    }

    /**
     * Install plan items for what's missing (Geyser, Floodgate and their
     * dependencies), ready for the Mods tab's guarded applyPlan.
     *
     * @return array<int, array>
     */
    public function planItems(object $server): array
    {
        $st = $this->status($server);
        if (! $st['supported']) {
            throw new \RuntimeException((string) $st['reason']);
        }
        $family = in_array($st['loader'], self::PLUGIN_LOADERS, true) ? 'plugin' : 'mod';

        $items = [];
        foreach (self::SOURCES[$family] as $part => [$source, $project]) {
            if ($st['installed'][$part]) {
                continue;
            }
            $versions = $this->mods->versionsFor($source, $project, $server);
            $first = $versions[0] ?? null;
            if ($first === null) {
                throw new \RuntimeException(ucfirst($part) . " has no build for {$st['loader']} on this Minecraft version.");
            }
            $vid = (string) (is_array($first) ? ($first['version_id'] ?? '') : ($first->versionId ?? ''));
            foreach ($this->mods->planInstall($source, $project, $vid, $server)->toArray()['items'] as $item) {
                $items[$item['source'] . ':' . $item['mod_id']] = $item;
            }
        }

        return array_values($items);
    }

    /**
     * Set Geyser up so Bedrock players can reach it (see the class comment).
     * Needs a restart. Returns what was set, for the UI.
     *
     * @return array{transport: string, port: int, webrtc_port: int}
     */
    public function configure(object $server): array
    {
        $st = $this->status($server);
        if (! $st['config_found']) {
            throw new \RuntimeException('Geyser\'s config isn\'t there yet. Start the server once so Geyser writes it.');
        }
        if (! $st['java_port']) {
            throw new \RuntimeException('This server has no primary allocation.');
        }
        $second = $st['second_port'];
        $set = ['transport' => $second ? 'both' : 'nethernet', 'port' => $st['java_port'], 'webrtc_port' => $second ?: 0];

        $yaml = (string) $this->daemon->readFile($server->uuid, $st['config'], 1048576);
        $new = self::withBedrockPort($yaml, $set['port']);
        foreach (['transport' => $set['transport'], 'webrtc-port' => (string) $set['webrtc_port']] as $key => $value) {
            $new = $new === null ? null : self::withBedrockValue($new, $key, $value);
        }
        $new = $new === null ? null : self::withSignalingMode($new, 'nxs');
        if ($new === null) {
            throw new \RuntimeException('Geyser\'s config doesn\'t have the expected bedrock settings (port, transport, webrtc-port, signaling.mode). Is Geyser up to date?');
        }
        $this->daemon->writeFile($server->uuid, $st['config'], $new);

        return $set + ['webrtc_port' => $set['webrtc_port'] ?: $set['port']];
    }

    public function configPath(string $loader): string
    {
        return match ($loader) {
            'fabric'   => '/config/Geyser-Fabric/config.yml',
            'neoforge' => '/config/Geyser-NeoForge/config.yml',
            default    => '/plugins/Geyser-Spigot/config.yml',
        };
    }

    /** bedrock.port from Geyser's config.yml, or null. */
    public static function bedrockPort(string $yaml): ?int
    {
        return preg_match('/^bedrock:[ \t]*\n(?:(?:[ \t]+[^\n]*|[ \t]*)\n)*?[ \t]{2}port:[ \t]*(\d+)/m', $yaml, $m) ? (int) $m[1] : null;
    }

    private const BEDROCK_KEY = '/^(bedrock:[ \t]*\n(?:(?:[ \t]+[^\n]*|[ \t]*)\n)*?[ \t]{2}%s:[ \t]*)(\S*)/m';
    private const SIGNALING_MODE = '/^(bedrock:[ \t]*\n(?:(?:[ \t]+[^\n]*|[ \t]*)\n)*?[ \t]{2}signaling:[ \t]*\n(?:(?:[ \t]{4,}[^\n]*|[ \t]*)\n)*?[ \t]{4}mode:[ \t]*)(\S*)/m';

    public static function bedrockValue(string $yaml, string $key): ?string
    {
        return preg_match(sprintf(self::BEDROCK_KEY, preg_quote($key, '/')), $yaml, $m) ? $m[2] : null;
    }

    public static function withBedrockValue(string $yaml, string $key, string $value): ?string
    {
        $out = preg_replace(sprintf(self::BEDROCK_KEY, preg_quote($key, '/')), '${1}' . $value, $yaml, 1, $count);

        return $count === 1 ? $out : null;
    }

    public static function signalingMode(string $yaml): ?string
    {
        return preg_match(self::SIGNALING_MODE, $yaml, $m) ? $m[2] : null;
    }

    public static function withSignalingMode(string $yaml, string $mode): ?string
    {
        $out = preg_replace(self::SIGNALING_MODE, '${1}' . $mode, $yaml, 1, $count);

        return $count === 1 ? $out : null;
    }

    /** The config with bedrock.port replaced (comments kept), or null if absent. */
    public static function withBedrockPort(string $yaml, int $port): ?string
    {
        $out = preg_replace('/^(bedrock:[ \t]*\n(?:(?:[ \t]+[^\n]*|[ \t]*)\n)*?[ \t]{2}port:[ \t]*)\d+/m', '${1}' . $port, $yaml, 1, $count);

        return $count === 1 ? $out : null;
    }

    private function installedPart(string $uuid, string $part): ?ServerMod
    {
        $ids = array_filter([self::SOURCES['plugin'][$part] ?? null, self::SOURCES['mod'][$part] ?? null]);

        return ServerMod::forServer($uuid)->where(function ($q) use ($ids) {
            foreach ($ids as [$source, $project]) {
                $q->orWhere(fn ($w) => $w->where('source', $source)->where('source_id', $project));
            }
        })->first();
    }
}
