<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\DB;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Network;
use ServerSuite\Models\NetworkMember;
use ServerSuite\Support\Config\ConfigEditor;
use ServerSuite\Support\GameGuard;
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
        return in_array(self::loaderOf($server), self::PROXIES, true);
    }

    public function networkFor(string $proxyUuid): ?Network
    {
        return Network::query()->where('proxy_uuid', $proxyUuid)->first();
    }

    /** The network a backend is in, with its proxy's name, and whether its setup finished. @return array{network: Network, proxy: ?string, ready: bool}|null */
    public function membership(string $serverUuid): ?array
    {
        $m = NetworkMember::query()->where('server_uuid', $serverUuid)->first();
        $n = $m ? Network::query()->find($m->network_id) : null;
        if ($n === null) {
            return null;
        }

        return ['network' => $n, 'proxy' => \Pterodactyl\Models\Server::query()->where('uuid', $n->proxy_uuid)->value('name'), 'ready' => in_array($m->setup_status, [null, 'ok'], true)];
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
            ->filter(fn ($s) => GameGuard::supports($s, 'network'))
            ->map(function ($s) use ($members, $taken) {
                $loader = self::loaderOf($s);
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
        $owned = \Pterodactyl\Models\Server::query()->where('owner_id', $proxy->owner_id)->whereIn('uuid', array_keys($backends))->get()->keyBy('uuid');
        $names = [];
        foreach ($backends as $uuid => $name) {
            $name = strtolower(trim($name));
            if (! isset($owned[$uuid]) || $uuid === $proxy->uuid) {
                throw new \RuntimeException('Only servers with the same owner as the proxy can join its network.');
            }
            if (! GameGuard::supports($owned[$uuid], 'network')) {
                throw new \RuntimeException("\"{$owned[$uuid]->name}\" can't join: Velocity only carries Minecraft Java Edition servers.");
            }
            if (! preg_match(self::NAME, $name) || $name === 'try') {
                throw new \RuntimeException("\"{$name}\" can't be a server name in Velocity: use 1-32 of a-z, 0-9, - and _.");
            }
            if (in_array($name, $names, true)) {
                throw new \RuntimeException("Two servers are both called \"{$name}\".");
            }
            if (in_array(self::loaderOf($owned[$uuid]), self::NOT_BACKENDS, true)) {
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
        $existing = $this->networkFor($proxy->uuid);
        $before = $existing?->members()->get()->map(fn ($m) => $m->only(['server_uuid', 'name', 'position', 'setup_status']))->all();
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

        if ($names === []) {
            $results = [$this->clearProxy($proxy->uuid)];
        } else {
            [$applied, $results] = $this->apply($network);
            if (! $applied) {
                // Nothing was written anywhere, so the network stays as it was.
                $this->restore($network, $before);

                return $results;
            }
        }
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
     * @return array{0: bool, 1: list<array{server: string, status: string, message: string}>} false: the proxy wasn't changed, so nothing was
     */
    private function apply(Network $network): array
    {
        $members = $network->members()->get();
        $servers = [];
        $results = [];
        foreach ($members as $m) {
            [$host, $port] = $this->cards->target($m->server_uuid);
            if ($host === null) {
                $results[] = $this->line($m->server_uuid, 'failed', 'Has no primary allocation (or is suspended), so the proxy can\'t reach it. Left out.');
                $m->forceFill(['setup_status' => 'failed'])->save();
                continue;
            }
            $servers[$m->name] = "{$host}:{$port}";
        }

        try {
            [$secret, $proxyLine] = $this->applyProxy($network->proxy_uuid, $servers);
            $results = array_merge([$proxyLine], $results);
        } catch (\RuntimeException $e) {
            return [false, array_merge([$this->line($network->proxy_uuid, 'failed', $e->getMessage() . ' Nothing was saved or changed.')], $results)];
        }

        foreach ($members as $m) {
            if (isset($servers[$m->name])) {
                $line = $this->join($m->server_uuid, $secret);
                $m->forceFill(['setup_status' => $line['status']])->save();
                $results[] = $line;
            }
        }
        $network->forceFill(['applied_at' => now()])->save();

        return [true, $results];
    }

    /**
     * Put a network back after an Apply that changed nothing.
     *
     * @param list<array<string, mixed>>|null $before its members, null: it didn't exist
     */
    private function restore(Network $network, ?array $before): void
    {
        DB::transaction(function () use ($network, $before) {
            NetworkMember::query()->where('network_id', $network->id)->delete();
            if ($before === null) {
                $network->delete();

                return;
            }
            foreach ($before as $member) {
                NetworkMember::query()->create($member + ['network_id' => $network->id]);
            }
        });
    }

    /**
     * The last backend left the network.
     *
     * @return array{server: string, status: string, message: string}
     */
    private function clearProxy(string $uuid): array
    {
        try {
            $raw = $this->daemon->readFile($uuid, '/velocity.toml', self::MAX_READ);
            if ($raw === null) {
                return $this->line($uuid, 'ok', 'No backends left.');
            }
            $this->write($uuid, '/velocity.toml', VelocityConfig::clear($raw));
        } catch (\RuntimeException $e) {
            return $this->line($uuid, 'action', 'The backends left, but velocity.toml still lists them: ' . $e->getMessage());
        }

        return $this->line($uuid, 'ok', 'velocity.toml: no servers left, so players have nowhere to go until you add one.');
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
        $server = \Pterodactyl\Models\Server::query()->where('uuid', $uuid)->first();

        return $server ? self::loaderOf($server) : null;
    }

    /**
     * The stored loader, else the egg's when it's clear enough to store: a
     * new server has nothing stored until its Mods page has been opened.
     */
    private static function loaderOf(object $server): ?string
    {
        $stored = ModManagerSetting::query()->where('server_uuid', (string) $server->uuid)->value('loader');
        if ($stored) {
            return strtolower((string) $stored);
        }
        try {
            $egg = app(LoaderDetectionService::class)->eggLoader($server);
        } catch (\Throwable) {
            return null;
        }

        return $egg->isConfident() ? strtolower((string) $egg->loader) : null;
    }
}
