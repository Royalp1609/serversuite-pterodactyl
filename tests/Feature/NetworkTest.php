<?php

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\NetworkService;
use ServerSuite\Support\PanelPermissions;
use ServerSuite\Support\VelocityConfig;
use ServerSuite\Tests\Fakes\PerServerFiles;
use Symfony\Component\Yaml\Yaml;

/**
 * #30: a Velocity proxy in front of the owner's servers. Fixtures are the
 * velocity.toml Velocity 4.3.0 writes and a real paper-global.yml.
 */

const SECRET = 'Kq3vS8x2NfLw';

function netServer(object $t, string $loader, array $files, string $name, int $owner = 1, int $port = 25565): Server
{
    $node = Node::query()->firstOrCreate(['name' => 'n1'], ['fqdn' => 'node.example']);
    $alloc = Allocation::query()->create(['node_id' => $node->id, 'ip' => '10.0.0.' . (Server::query()->count() + 2), 'port' => $port]);
    $s = Server::query()->create(['uuid' => 'uuid-' . strtolower(str_replace(' ', '-', $name)), 'name' => $name, 'owner_id' => $owner, 'node_id' => $node->id, 'allocation_id' => $alloc->id]);
    ModManagerSetting::forServer($s->uuid)->forceFill(['loader' => $loader])->save();
    foreach ($files as $path => $content) {
        $t->files->for($s->uuid)->put($path, $content);
    }

    return $s;
}

function fx(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../Fixtures/' . $name);
}

beforeEach(function () {
    panelTables();
    $this->files = new PerServerFiles();
    $this->app->instance(DaemonFileClient::class, $this->files);
    $props = "#Minecraft server properties\nonline-mode=true\nmotd=A Minecraft Server\n";
    $this->proxy = netServer($this, 'velocity', ['/velocity.toml' => fx('velocity.toml'), '/forwarding.secret' => SECRET], 'Proxy');
    $this->paper = netServer($this, 'paper', ['/config/paper-global.yml' => fx('paper-global.yml'), '/server.properties' => $props], 'Survival SMP');
    $this->fabric = netServer($this, 'fabric', ['/config/FabricProxy-Lite.toml' => "hackOnlineMode = true\nsecret = \"\"\n", '/server.properties' => $props], 'Creative');
    $this->forge = netServer($this, 'forge', ['/server.properties' => $props], 'Modded');
});

function nfile(object $t, Server $s, string $path): string
{
    return (string) $t->files->for($s->uuid)->readFile($s->uuid, $path, 4194304);
}

it('configures the proxy and each backend', function () {
    $results = app(NetworkService::class)->save($this->proxy, [$this->paper->uuid => 'survival', $this->fabric->uuid => 'creative', $this->forge->uuid => 'modded']);

    expect(array_column($results, 'status', 'server'))->toBe(['Proxy' => 'ok', 'Survival SMP' => 'ok', 'Creative' => 'ok', 'Modded' => 'unsupported']);
    $toml = nfile($this, $this->proxy, '/velocity.toml');
    expect($toml)->toContain('player-info-forwarding-mode = "MODERN"')
        ->toContain('try = ["survival", "creative", "modded"]')
        ->toContain('# What port should the proxy be bound to?')
        ->not->toContain('lobby.example.com');
    expect(Yaml::parse(nfile($this, $this->paper, '/config/paper-global.yml'))['proxies']['velocity'])->toBe(['enabled' => true, 'online-mode' => true, 'secret' => SECRET])
        ->and(nfile($this, $this->paper, '/server.properties'))->toContain("online-mode=false\n")
        ->and(nfile($this, $this->fabric, '/config/FabricProxy-Lite.toml'))->toBe("hackOnlineMode = true\nsecret = \"" . SECRET . "\"\n")
        ->and(nfile($this, $this->forge, '/server.properties'))->toContain("online-mode=true\n");
});

it('sets back a server taken out', function () {
    $svc = app(NetworkService::class);
    $svc->save($this->proxy, [$this->paper->uuid => 'survival', $this->fabric->uuid => 'creative']);
    $svc->save($this->proxy, [$this->fabric->uuid => 'creative']);

    expect(Yaml::parse(nfile($this, $this->paper, '/config/paper-global.yml'))['proxies']['velocity']['enabled'])->toBeFalse()
        ->and(nfile($this, $this->paper, '/server.properties'))->toContain("online-mode=true\n")
        ->and(nfile($this, $this->proxy, '/velocity.toml'))->toContain('try = ["creative"]');
});

it('only takes the owner\'s servers and valid names', function () {
    $stranger = netServer($this, 'paper', [], 'Theirs', owner: 2);
    foreach ([[[$stranger->uuid => 'theirs'], 'same owner'], [[$this->paper->uuid => 'Has Space'], "can't be a server name"],
        [[$this->paper->uuid => 'try'], "can't be a server name"], [[$this->paper->uuid => 'a', $this->fabric->uuid => 'a'], 'both called']] as [$b, $msg]) {
        expect(fn () => app(NetworkService::class)->save($this->proxy, $b))->toThrow(RuntimeException::class, $msg);
    }
    expect(nfile($this, $this->proxy, '/velocity.toml'))->toBe(fx('velocity.toml'));
});

it('keeps forced hosts for servers that exist', function () {
    $raw = str_replace('"lobby.example.com" = [', '"play.example.com" = ["survival"]' . "\n" . '"lobby.example.com" = [', fx('velocity.toml'));
    $out = VelocityConfig::apply($raw, ['survival' => '10.0.0.2:25565']);

    expect($out)->toContain('"play.example.com" = ["survival"]')->not->toContain('lobby.example.com');
});

it('is for the owner or a root admin only', function () {
    $owner = (object) ['id' => 1, 'root_admin' => false];
    $admin = (object) ['id' => 9, 'root_admin' => true];
    $sub = new class {
        public $id = 5;
        public $root_admin = false;
        public function can($p, $s) { return true; }   // holds every grantable permission
    };

    expect(PanelPermissions::allows($owner, $this->proxy, 'networkApply'))->toBeTrue()
        ->and(PanelPermissions::allows($admin, $this->proxy, 'networkApply'))->toBeTrue()
        ->and(PanelPermissions::allows($sub, $this->proxy, 'networkApply'))->toBeFalse();
});
