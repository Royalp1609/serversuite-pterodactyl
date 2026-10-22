<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\DB;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Network;
use ServerSuite\Models\NetworkMember;
use ServerSuite\Support\Config\ConfigEditor;
use ServerSuite\Support\VelocityConfig;

/**
 * Velocity networks (#30): one Velocity server in front of backend servers
 * that the same user owns.
 *
 * Applying a network writes, on the proxy, velocity.toml's [servers] and try
 * list and modern forwarding (keeping the proxy's own forwarding secret);
 * on each backend, the proxy's secret and online-mode=false: Paper/Purpur/
 * Folia through config/paper-global.yml, Fabric/Quilt through
 * FabricProxy-Lite's config. A server taken out of the network is set back
 * (forwarding off, online-mode=true). Edits are in place (#8 editor) and
 * every server reports what happened. The changes apply on restart.
 *
 * Only the owner of all the servers (or a root admin) may change a network:
 * it writes to servers other than the one the page is on.
 */
class NetworkService
{
    public const PROXIES = ['velocity'];
    private const NOT_BACKENDS = ['velocity', 'waterfall', 'bungeecord'];
    private const PAPER = ['paper', 'purpur', 'folia', 'pufferfish'];
    private const FABRIC = ['fabric', 'quilt'];
    private const NAME = '/^[a-z0-9_-]{1,32}$/';
    private const MAX_READ = 2097152;

    public function __construct(protected DaemonFileClient $daemon, protected StatusCardService $cards) {}

    public static function isProxy(object $server): bool
    {
        return in_array(self::loader((string) $server->uuid), self::PROXIES, true);
    }

    public function networkFor(string $proxyUuid): ?Network
    {
        return Network::query()->where('proxy_uuid', $proxyUuid)->first();
    }

    /** The network a backend is in, with its proxy's name. @return array{network: Network, proxy: ?string}|null */
    public function membership(string $serverUuid): ?array
    {
        $m = NetworkMember::query()->where('server_uuid', $serverUuid)->first();
        $n = $m ? Network::query()->find($m->network_id) : null;
        if ($n === null) {
            return null;
        }

        return ['network' => $n, 'proxy' => \Pterodactyl\Models\Server::query()->where('uuid', $n->proxy_uuid)->value('name')];
    }

    /**
     * The proxy owner's other servers, with what the network page needs.
     *
     * @return list<array{uuid: string, name: string, loader: ?string, supported: bool, address: ?string, member: ?string, position: ?int, elsewhere: bool}>
     */
    public function candidates(object $proxy): array
    {
        $network = $this->networkFor($proxy->uuid);
        $members = $network ? $network->members()->get()->keyBy('server_uuid') : collect();
        $taken = NetworkMember::query()->when($network, fn ($q) => $q->where('network_id', '!=', $network->id))->pluck('server_uuid')->all();

        return \Pterodactyl\Models\Server::query()->where('owner_id', $proxy->owner_id)->where('uuid', '!=', $proxy->uuid)->orderBy('name')->get()
            ->map(function ($s) use ($members, $taken) {
                $loader = self::loader($s->uuid);
                [$host, $port] = $this->cards->target($s->uuid);

                return [
                    'uuid' => (string) $s->uuid, 'name' => (string) $s->name, 'loader' => $loader,
                    'supported' => in_array($loader, array_merge(self::PAPER, self::FABRIC), true),
                    'address' => $host !== null ? "{$host}:{$port}" : null,
                    'member' => $members[$s->uuid]->name ?? null,
                    'position' => $members[$s->uuid]->position ?? null,
                    'elsewhere' => in_array($s->uuid, $taken, true),
                ];
            })
            ->filter(fn ($c) => ! in_array($c['loader'], self::NOT_BACKENDS, true))
            ->values()->all();
    }

    /**
     * Save the proxy's backends (uuid => velocity name, in try order) and
     * apply the network. Returns a line per server.
     *
     * @param array<string, string> $backends
     *
     * @return list<array{server: string, status: string, message: string}>
     *
     * @throws \RuntimeException
     */
    public function save(object $proxy, array $backends): array
    {
        if (! self::isProxy($proxy)) {
            throw new \RuntimeException('Networks are set up from a Velocity server.');
        }
        $owned = \Pterodactyl\Models\Server::query()->where('owner_id', $proxy->owner_id)->whereIn('uuid', array_keys($backends))->pluck('uuid')->all();
        $names = [];
        foreach ($backends as $uuid => $name) {
            $name = strtolower(trim($name));
            if (! in_array($uuid, $owned, true) || $uuid === $proxy->uuid) {
                throw new \RuntimeException('Only servers with the same owner as the proxy can join its network.');
            }
            if (! preg_match(self::NAME, $name) || $name === 'try') {
                throw new \RuntimeException("\"{$name}\" can't be a server name in Velocity: use 1-32 of a-z, 0-9, - and _.");
            }
            if (in_array($name, $names, true)) {
                throw new \RuntimeException("Two servers are both called \"{$name}\".");
            }
            if (in_array(self::loader($uuid), self::NOT_BACKENDS, true)) {
                throw new \RuntimeException('A proxy can\'t be a backend.');
            }
            $names[$uuid] = $name;
        }
        $elsewhere = NetworkMember::query()->whereIn('server_uuid', array_keys($names))
            ->whereNot('network_id', $this->networkFor($proxy->uuid)->id ?? 0)->exists();
        if ($elsewhere) {
            throw new \RuntimeException('A server can only be in one network. Take it out of the other one first.');
        }

        $left = [];
        $network = DB::transaction(function () use ($proxy, $names, &$left) {
            $network = $this->networkFor($proxy->uuid) ?? Network::query()->create(['owner_id' => $proxy->owner_id, 'proxy_uuid' => $proxy->uuid]);
            $left = NetworkMember::query()->where('network_id', $network->id)->whereNotIn('server_uuid', array_keys($names))->pluck('server_uuid')->all();
            NetworkMember::query()->where('network_id', $network->id)->delete();
            $pos = 0;
            foreach ($names as $uuid => $name) {
                NetworkMember::query()->create(['network_id' => $network->id, 'server_uuid' => $uuid, 'name' => $name, 'position' => $pos++]);
            }

            return $network;
        });

        $results = $this->apply($network);
        foreach ($left as $uuid) {
            $results[] = $this->leave($uuid);
        }
        if ($names === []) {
            $network->delete();
        }

        return $results;
    }

    /**
     * Write the network's settings to the proxy and every backend.
     *
     * @return list<array{server: string, status: string, message: string}>
     */
    public function apply(Network $network): array
    {
        $members = $network->members()->get();
        if ($members->isEmpty()) {
            return [];
        }
        $servers = [];
        $results = [];
        foreach ($members as $m) {
            [$host, $port] = $this->cards->target($m->server_uuid);
            if ($host === null) {
                $results[] = $this->line($m->server_uuid, 'failed', 'Has no primary allocation (or is suspended), so the proxy can\'t reach it. Left out.');
                continue;
            }
            $servers[$m->name] = "{$host}:{$port}";
        }

        try {
            [$secret, $proxyLine] = $this->applyProxy($network->proxy_uuid, $servers);
            $results = array_merge([$proxyLine], $results);
        } catch (\RuntimeException $e) {
            return array_merge([$this->line($network->proxy_uuid, 'failed', $e->getMessage() . ' Nothing was changed on the backends.')], $results);
        }

        foreach ($members as $m) {
            if (isset($servers[$m->name])) {
                $results[] = $this->join($m->server_uuid, $secret);
            }
        }
        $network->forceFill(['applied_at' => now()])->save();

        return $results;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $servers
     *
     * @return array{0: string, 1: array{server: string, status: string, message: string}}
     *
     * @throws \RuntimeException
     */
    private function applyProxy(string $uuid, array $servers): array
    {
        if ($servers === []) {
            throw new \RuntimeException('No backend can be reached.');
        }
        $raw = $this->daemon->readFile($uuid, '/velocity.toml', self::MAX_READ)
            ?? throw new \RuntimeException('There is no velocity.toml yet: start the proxy once so Velocity writes it.');
        $secretFile = '/' . VelocityConfig::secretFile($raw);
        $secret = trim((string) $this->daemon->readFile($uuid, $secretFile, 4096));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            $this->write($uuid, $secretFile, $secret);
        }
        $this->write($uuid, '/velocity.toml', VelocityConfig::apply($raw, $servers));

        return [$secret, $this->line($uuid, 'ok', 'velocity.toml: ' . count($servers) . ' server(s), try order ' . implode(', ', array_keys($servers)) . ', modern forwarding.')];
    }

    /** @return array{server: string, status: string, message: string} */
    private function join(string $uuid, string $secret): array
    {
        $loader = self::loader($uuid);
        try {
            if (in_array($loader, self::PAPER, true)) {
                $this->edit($uuid, '/config/paper-global.yml', ['proxies.velocity.enabled' => true, 'proxies.velocity.online-mode' => true, 'proxies.velocity.secret' => $secret],
                    'Start this server once so Paper writes config/paper-global.yml, then apply again.');
            } elseif (in_array($loader, self::FABRIC, true)) {
                $this->edit($uuid, '/config/FabricProxy-Lite.toml', ['secret' => $secret],
                    'Install FabricProxy-Lite (Mods page) and start the server once, then apply again.');
            } else {
                return $this->line($uuid, 'unsupported', ($loader ? ucfirst($loader) : 'This loader') . " can't be set up automatically (Paper, Purpur, Folia, Fabric and Quilt can). Set its forwarding by hand.");
            }
            $this->edit($uuid, '/server.properties', ['online-mode' => false], 'Start this server once so it writes server.properties, then apply again.');
        } catch (\RuntimeException $e) {
            return $this->line($uuid, 'action', $e->getMessage());
        }

        return $this->line($uuid, 'ok', 'Forwarding secret set, online-mode=false. Players can only join through the proxy.');
    }

    /** @return array{server: string, status: string, message: string} */
    private function leave(string $uuid): array
    {
        try {
            if (in_array(self::loader($uuid), self::PAPER, true)) {
                $this->edit($uuid, '/config/paper-global.yml', ['proxies.velocity.enabled' => false], null);
            }
            $this->edit($uuid, '/server.properties', ['online-mode' => true], null);
        } catch (\RuntimeException $e) {
            return $this->line($uuid, 'action', 'Left the network, but: ' . $e->getMessage());
        }
        $fabric = in_array(self::loader($uuid), self::FABRIC, true);

        return $this->line($uuid, 'ok', 'Left the network: online-mode=true' . ($fabric ? '. Remove FabricProxy-Lite too, or players can\'t join directly.' : ', Velocity forwarding off.'));
    }

    /**
     * Set settings in a config file in place (#8 editor). A setting the
     * file doesn't have is an error with $missing as the message.
     *
     * @param array<string, mixed> $values label => value
     *
     * @throws \RuntimeException
     */
    private function edit(string $uuid, string $path, array $values, ?string $missing): void
    {
        $raw = $this->daemon->readFile($uuid, $path, self::MAX_READ);
        if ($raw === null) {
            if ($missing === null) {
                return;
            }
            throw new \RuntimeException($missing);
        }
        $fields = collect(ConfigEditor::open($raw, basename($path))['fields'])->keyBy('label');
        $input = [];
        foreach ($values as $label => $value) {
            $f = $fields[$label] ?? throw new \RuntimeException(basename($path) . " has no {$label} setting the form can edit." . ($missing ? " {$missing}" : ''));
            $input[$f['id']] = $value;
        }
        $out = ConfigEditor::save($raw, basename($path), $input);
        if ($out['changed'] !== []) {
            $this->write($uuid, $path, $out['content']);
        }
    }

    private function write(string $uuid, string $path, string $content): void
    {
        if (! method_exists($this->daemon, 'writeFile')) {
            throw new \RuntimeException('This panel build cannot write files through the plugin.');
        }
        $this->daemon->writeFile($uuid, $path, $content);
    }

    /** @return array{server: string, status: string, message: string} */
    private function line(string $uuid, string $status, string $message): array
    {
        return ['server' => (string) (\Pterodactyl\Models\Server::query()->where('uuid', $uuid)->value('name') ?? $uuid), 'status' => $status, 'message' => $message];
    }

    private static function loader(string $uuid): ?string
    {
        $l = ModManagerSetting::query()->where('server_uuid', $uuid)->value('loader');

        return $l ? strtolower((string) $l) : null;
    }
}
