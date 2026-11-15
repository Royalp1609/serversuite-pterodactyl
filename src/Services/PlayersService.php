<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Support\Config\ConfigEditor;
use ServerSuite\Support\MinecraftPing;

/**
 * Players (#27): the whitelist, ops and bans of a Minecraft server as lists.
 *
 * While the server runs, changes go in as console commands (`whitelist add`,
 * `op`, `ban`, ...), so they apply at once and the server writes its own
 * files. While it's stopped, the JSON files are edited through Wings (the
 * server reads them on start). Names are looked up at Mojang for their UUID,
 * or given the offline UUID when online-mode is off. Every name, IP and
 * reason is checked before it goes near the console.
 */
class PlayersService
{
    public const LISTS = [
        'whitelist' => '/whitelist.json',
        'ops' => '/ops.json',
        'banned-players' => '/banned-players.json',
        'banned-ips' => '/banned-ips.json',
    ];

    private const PROPERTIES = '/server.properties';
    private const MAX_READ = 2097152;
    private const NAME = '/^[A-Za-z0-9_]{1,16}$/';

    /** @var (callable(object, string): void)|null test hook: send a console command */
    private $sender;
    /** @var (callable(object): bool)|null test hook: is the server running */
    private $running;

    public function __construct(protected DaemonFileClient $daemon, protected StatusCardService $cards, ?callable $sender = null, ?callable $running = null)
    {
        $this->sender = $sender;
        $this->running = $running;
    }

    /**
     * @return array{found: bool, running: bool, online_mode: bool, whitelist_on: bool, lists: array<string, list<array<string, mixed>>>, online: ?array{count: ?int, max: ?int, names: list<string>}}
     */
    public function overview(object $server): array
    {
        $props = $this->properties($server);
        $lists = [];
        foreach (self::LISTS as $key => $path) {
            $lists[$key] = $this->readList($server, $path);
        }
        $running = $this->isRunning($server);

        return [
            'found' => $props !== null,
            'running' => $running,
            'online_mode' => ($props['online-mode'] ?? 'true') !== 'false',
            'whitelist_on' => ($props['white-list'] ?? 'false') === 'true',
            'lists' => $lists,
            'online' => $running ? $this->online($server) : null,
        ];
    }

    /**
     * Add a player (or, for banned-ips, an address) to a list.
     *
     * @throws \RuntimeException with a message for the user
     */
    public function add(object $server, string $list, string $who, string $reason = ''): string
    {
        $reason = $this->reason($reason);
        if ($list === 'banned-ips') {
            $ip = $this->ip($who);

            return $this->change($server, "ban-ip {$ip}" . ($reason !== '' ? " {$reason}" : ''), function (array $rows) use ($ip, $reason) {
                $rows = array_values(array_filter($rows, fn ($r) => ($r['ip'] ?? null) !== $ip));
                $rows[] = ['ip' => $ip, 'created' => $this->now(), 'source' => 'Server Suite', 'expires' => 'forever', 'reason' => $reason !== '' ? $reason : 'Banned by an operator.'];

                return $rows;
            }, $list, "{$ip} banned");
        }

        $name = $this->name($who);
        $props = $this->properties($server) ?? [];
        $player = $this->lookup($name, ($props['online-mode'] ?? 'true') !== 'false');
        $command = match ($list) {
            'whitelist' => "whitelist add {$player['name']}",
            'ops' => "op {$player['name']}",
            'banned-players' => "ban {$player['name']}" . ($reason !== '' ? " {$reason}" : ''),
            default => throw new \RuntimeException('Unknown list.'),
        };

        return $this->change($server, $command, function (array $rows) use ($list, $player, $props, $reason) {
            $rows = array_values(array_filter($rows, fn ($r) => strcasecmp((string) ($r['uuid'] ?? ''), $player['uuid']) !== 0));
            $rows[] = match ($list) {
                'whitelist' => $player,
                'ops' => $player + ['level' => max(1, min(4, (int) ($props['op-permission-level'] ?? 4))), 'bypassesPlayerLimit' => false],
                default => $player + ['created' => $this->now(), 'source' => 'Server Suite', 'expires' => 'forever', 'reason' => $reason !== '' ? $reason : 'Banned by an operator.'],
            };

            return $rows;
        }, $list, "{$player['name']} added");
    }

    /** @throws \RuntimeException */
    public function remove(object $server, string $list, string $who): string
    {
        if ($list === 'banned-ips') {
            $ip = $this->ip($who);

            return $this->change($server, "pardon-ip {$ip}", fn (array $rows) => array_values(array_filter($rows, fn ($r) => ($r['ip'] ?? null) !== $ip)), $list, "{$ip} unbanned");
        }
        $name = $this->name($who, true);
        $command = match ($list) {
            'whitelist' => "whitelist remove {$name}",
            'ops' => "deop {$name}",
            'banned-players' => "pardon {$name}",
            default => throw new \RuntimeException('Unknown list.'),
        };

        return $this->change($server, $command, fn (array $rows) => array_values(array_filter($rows, fn ($r) => strcasecmp((string) ($r['name'] ?? ''), $name) !== 0)), $list, "{$name} removed");
    }

    /** Turn the whitelist on or off. @throws \RuntimeException */
    public function setWhitelist(object $server, bool $on): string
    {
        if ($this->isRunning($server)) {
            $this->send($server, $on ? 'whitelist on' : 'whitelist off');

            return $on ? 'Whitelist on.' : 'Whitelist off.';
        }
        $raw = $this->daemon->readFile($server->uuid, self::PROPERTIES, self::MAX_READ)
            ?? throw new \RuntimeException('This server has no server.properties yet. Start it once first.');
        $form = ConfigEditor::open($raw, 'server.properties');
        $field = collect($form['fields'])->firstWhere('label', 'white-list')
            ?? throw new \RuntimeException('server.properties has no white-list setting. Start the server once to add it.');
        $out = ConfigEditor::save($raw, 'server.properties', [$field['id'] => $on]);
        $this->write($server, self::PROPERTIES, $out['content']);

        return ($on ? 'Whitelist on' : 'Whitelist off') . '. It applies when the server starts.';
    }

    // -------------------------------------------------------------------------

    /**
     * @param callable(list<array<string, mixed>>): list<array<string, mixed>> $edit
     *
     * @throws \RuntimeException
     */
    private function change(object $server, string $command, callable $edit, string $list, string $done): string
    {
        if ($this->isRunning($server)) {
            $this->send($server, $command);

            return "{$done}.";
        }
        $path = self::LISTS[$list];
        $rows = $this->readList($server, $path);
        $this->write($server, $path, json_encode($edit($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        return "{$done}. It applies when the server starts.";
    }

    /** @return list<array<string, mixed>> */
    private function readList(object $server, string $path): array
    {
        $raw = $this->daemon->readFile($server->uuid, $path, self::MAX_READ);
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $rows = json_decode($raw, true);

        return is_array($rows) && array_is_list($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return array<string, string>|null */
    private function properties(object $server): ?array
    {
        $raw = $this->daemon->readFile($server->uuid, self::PROPERTIES, self::MAX_READ);
        if ($raw === null) {
            return null;
        }
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9.\-]+)\s*=\s*(.*?)\s*$/i', $line, $m)) {
                $out[strtolower($m[1])] = strtolower($m[2]) === 'true' || strtolower($m[2]) === 'false' ? strtolower($m[2]) : $m[2];
            }
        }

        return $out;
    }

    /**
     * Name and dashed UUID: Mojang's for online-mode servers, the offline
     * one (Java's UUID.nameUUIDFromBytes("OfflinePlayer:" + name)) otherwise.
     *
     * @return array{uuid: string, name: string}
     *
     * @throws \RuntimeException
     */
    public function lookup(string $name, bool $onlineMode): array
    {
        if (! $onlineMode) {
            $h = md5('OfflinePlayer:' . $name, true);
            $h[6] = chr(ord($h[6]) & 0x0F | 0x30);
            $h[8] = chr(ord($h[8]) & 0x3F | 0x80);

            return ['uuid' => self::dashed(bin2hex($h)), 'name' => $name];
        }

        $found = Cache::remember('serversuite:mojang:' . strtolower($name), now()->addHour(), function () use ($name) {
            try {
                $r = Http::timeout(6)->acceptJson()->get('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($name));
            } catch (\Throwable) {
                return null;
            }
            if ($r->status() === 404 || $r->status() === 204) {
                return false;
            }
            if ($r->status() === 429) {
                return 'limited';   // item 111: busy, not unreachable
            }

            return $r->successful() && is_string($r->json('id')) ? ['uuid' => self::dashed($r->json('id')), 'name' => (string) $r->json('name')] : null;
        });
        if ($found === false) {
            Cache::forget('serversuite:mojang:' . strtolower($name));
            throw new \RuntimeException("There is no Minecraft account called {$name}.");
        }
        if ($found === 'limited') {
            Cache::forget('serversuite:mojang:' . strtolower($name));
            throw new \RuntimeException("Mojang is limiting lookups right now, so {$name} couldn't be checked. Try again in a minute.");
        }
        if ($found === null) {
            Cache::forget('serversuite:mojang:' . strtolower($name));
            throw new \RuntimeException("Couldn't reach Mojang to look up {$name}. Try again in a minute.");
        }

        return $found;
    }

    /** @return array{count: ?int, max: ?int, names: list<string>}|null */
    private function online(object $server): ?array
    {
        [$host, $port] = $this->cards->target($server->uuid);
        if ($host === null || $port === null) {
            return null;
        }
        try {
            $s = app(MinecraftPing::class)->ping($host, $port, $host);
        } catch (\Throwable) {
            return null;
        }
        $names = array_values(array_filter(array_map(fn ($p) => is_array($p) ? (string) ($p['name'] ?? '') : '', (array) ($s['players']['sample'] ?? [])),
            fn ($n) => preg_match(self::NAME, $n) === 1));

        return ['count' => isset($s['players']['online']) ? (int) $s['players']['online'] : null, 'max' => isset($s['players']['max']) ? (int) $s['players']['max'] : null, 'names' => $names];
    }

    private function isRunning(object $server): bool
    {
        if ($this->running !== null) {
            return ($this->running)($server);
        }
        try {
            $d = app('\\Pterodactyl\\Repositories\\Wings\\DaemonServerRepository')->setServer($server)->getDetails();
        } catch (\Throwable) {
            return false;
        }

        return ($d['state'] ?? ($d['utilization']['state'] ?? null)) === 'running';
    }

    /** @throws \RuntimeException */
    private function send(object $server, string $command): void
    {
        try {
            $this->sender !== null
                ? ($this->sender)($server, $command)
                : app('\\Pterodactyl\\Repositories\\Wings\\DaemonCommandRepository')->setServer($server)->send($command);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The server didn\'t take the command: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @throws \RuntimeException */
    private function write(object $server, string $path, string $content): void
    {
        if (! method_exists($this->daemon, 'writeFile')) {
            throw new \RuntimeException('This panel build cannot write files through the plugin.');
        }
        $this->daemon->writeFile($server->uuid, $path, $content);
    }

    /** @throws \RuntimeException */
    private function name(string $who, bool $existing = false): string
    {
        $name = trim($who);
        // Removing also takes Bedrock players added through Floodgate (".Name").
        if (! preg_match($existing ? '/^\.?[A-Za-z0-9_]{1,30}$/' : self::NAME, $name)) {
            throw new \RuntimeException('A Java player name is 1-16 letters, digits or underscores.');
        }

        return $name;
    }

    /** @throws \RuntimeException */
    private function ip(string $who): string
    {
        $ip = filter_var(trim($who), FILTER_VALIDATE_IP);
        if ($ip === false) {
            throw new \RuntimeException('Enter an IP address, like 203.0.113.7.');
        }

        return $ip;
    }

    /** One line of plain text: it goes on the end of a console command. */
    private function reason(string $reason): string
    {
        // Invalid UTF-8 is repaired, not dropped (item 112): /u gives up on the whole string.
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', mb_scrub($reason, 'UTF-8'))), 0, 120);
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s O');
    }

    private static function dashed(string $hex): string
    {
        $hex = strtolower($hex);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
