<?php

use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use ServerSuite\Exceptions\SubdomainException;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\StatusCardService;
use ServerSuite\Services\SubdomainService;
use ServerSuite\Support\MinecraftPing;

/**
 * Public status cards (#29): opt-in per subdomain, no panel details in the
 * output, one ping per cache window, and the Server List Ping itself.
 */

beforeEach(function () {
    panelTables();
    Cache::flush();

    $node = Node::query()->create(['name' => 'secret-node', 'fqdn' => 'wings.internal.example']);
    $alloc = Allocation::query()->create(['node_id' => $node->id, 'ip' => '192.0.2.44', 'port' => 25565]);
    $this->server = Server::query()->create(['uuid' => 'c0ffee00-1111-2222-3333-444455556666', 'node_id' => $node->id, 'allocation_id' => $alloc->id]);

    $this->pings = [];
    $this->reply = [
        'version'     => ['name' => 'Paper 26.1.2', 'protocol' => 800],
        'players'     => ['online' => 3, 'max' => 20, 'sample' => [['name' => 'SecretPlayer', 'id' => '0']]],
        'description' => ['text' => "\u{00A7}aWelcome ", 'extra' => [['text' => 'to the <b>server</b>']]],
        'favicon'     => 'data:image/png;base64,iVBORw0KGgo=',
    ];
    $this->app->instance(StatusCardService::class, new StatusCardService(function (string $host, int $port, string $hostname) {
        $this->pings[] = [$host, $port, $hostname];
        if ($this->reply instanceof Throwable) {
            throw $this->reply;
        }

        return $this->reply;
    }));
});

function card(bool $public = true, string $fqdn = 'play.example.com', string $uuid = 'c0ffee00-1111-2222-3333-444455556666'): Subdomain
{
    return Subdomain::query()->create([
        'server_uuid' => $uuid, 'provider' => 'cloudflare', 'label' => strtok($fqdn, '.'),
        'zone' => 'example.com', 'fqdn' => $fqdn, 'ip' => '203.0.113.10', 'port' => 25565, 'status_public' => $public,
    ]);
}

it('is off until the owner switches it on', function () {
    card(false);

    $this->get('/status/play.example.com')->assertNotFound();
    $this->get('/status/play.example.com/badge.svg')->assertNotFound();
    $this->get('/status/nobody.example.com')->assertNotFound();
    expect($this->pings)->toBe([], 'nothing pinged for a hidden card');
});

it('shows the ping and nothing from the panel', function () {
    card();

    $page = $this->get('/status/PLAY.example.com.')->assertOk();
    $html = $page->getContent();

    expect($html)->toContain('Online')
        ->toContain('3/20 players')
        ->toContain('Welcome to the &lt;b&gt;server&lt;/b&gt;')   // MOTD flattened, colour codes gone, escaped
        ->toContain('Paper 26.1.2')
        ->toContain('data:image/png;base64,iVBORw0KGgo=');
    foreach ([$this->server->uuid, '192.0.2.44', 'wings.internal.example', 'secret-node', 'SecretPlayer'] as $secret) {
        expect($html)->not->toContain($secret);
    }
    expect($page->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');
    expect($page->headers->has('Set-Cookie'))->toBeFalse('no session for a public card');

    // The panel pings the allocation directly, naming the subdomain in the handshake.
    expect($this->pings)->toBe([['192.0.2.44', 25565, 'play.example.com']]);
});

it('serves a well-formed SVG badge', function () {
    card();

    $badge = $this->get('/status/play.example.com/badge.svg')->assertOk();

    expect($badge->headers->get('Content-Type'))->toStartWith('image/svg+xml')
        ->and($badge->getContent())->toContain('3/20 online')
        ->and(simplexml_load_string($badge->getContent()))->not->toBeFalse();
});

it('pings once per cache window however many views', function () {
    card();

    $this->get('/status/play.example.com')->assertOk();
    $this->get('/status/play.example.com/badge.svg')->assertOk();
    $this->get('/status/play.example.com?embed=1')->assertOk();

    expect($this->pings)->toHaveCount(1);
});

it('shows offline when the server does not answer, and never pings a suspended one', function () {
    $sub = card();
    $this->reply = new RuntimeException('connect failed');

    expect($this->get('/status/play.example.com')->getContent())->toContain('Offline');

    Cache::flush();
    $this->pings = [];
    $this->server->forceFill(['status' => 'suspended'])->save();
    expect(app(StatusCardService::class)->status($sub)['online'])->toBeFalse()
        ->and($this->pings)->toBe([]);
});

it('pings a server bound to every interface on its node', function () {
    $sub = card();
    Allocation::query()->whereKey($this->server->allocation_id)->update(['ip' => '0.0.0.0']);

    app(StatusCardService::class)->status($sub);

    expect($this->pings[0][0])->toBe('wings.internal.example');
});

it('drops icons that are not PNG data', function () {
    $sub = card();
    $this->reply['favicon'] = 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=';

    expect(app(StatusCardService::class)->status($sub)['icon'])->toBeNull();
});

it('hides every card when the admin switch is off', function () {
    card();
    config(['serversuite.status_cards.enabled' => false]);

    $this->get('/status/play.example.com')->assertNotFound();
    expect(app(SubdomainService::class)->overview($this->server)['status_cards'])->toBeFalse();
});

it("switches a card only on the server's own subdomain, and lists the links", function () {
    $mine = card(false);
    $theirs = card(false, 'theirs.example.com', 'other-server');
    $cards = app(StatusCardService::class);

    expect(fn () => $cards->setPublic($this->server, $theirs->id, true))->toThrow(SubdomainException::class, 'not found');
    expect($theirs->fresh()->status_public)->toBeFalse();

    $cards->setPublic($this->server, $mine->id, true);
    $item = app(SubdomainService::class)->overview($this->server)['items'][0];
    expect($item['status_public'])->toBeTrue()
        ->and($item['card_url'])->toEndWith('/status/play.example.com')
        ->and($item['badge_url'])->toEndWith('/status/play.example.com/badge.svg');

    $cards->setPublic($this->server, $mine->id, false);
    $item = app(SubdomainService::class)->overview($this->server)['items'][0];
    expect($item['card_url'])->toBeNull();
    $this->get('/status/play.example.com')->assertNotFound();
});

it('speaks the Server List Ping protocol', function () {
    // A one-shot fake Minecraft server in a child PHP process.
    $port = random_int(40000, 49000);
    $status = json_encode(['version' => ['name' => '1.21'], 'players' => ['online' => 1, 'max' => 5], 'description' => 'hi']);
    $script = <<<'PHP'
        $s = stream_socket_server('tcp://127.0.0.1:' . $argv[1]);
        fwrite(STDOUT, "ready\n");
        $c = stream_socket_accept($s, 5);
        $read = function () use ($c) { $v = 0; for ($i = 0; $i < 5; $i++) { $b = ord(fread($c, 1)); $v |= ($b & 0x7F) << (7 * $i); if (!($b & 0x80)) return $v; } };
        $hs = fread($c, $read());
        file_put_contents($argv[3], bin2hex($hs));
        $read(); fread($c, 1);
        $vi = function ($v) { $o = ''; do { $b = $v & 0x7F; $v >>= 7; $o .= chr($v ? $b | 0x80 : $b); } while ($v); return $o; };
        $body = "\x00" . $vi(strlen($argv[2])) . $argv[2];
        fwrite($c, $vi(strlen($body)) . $body);
        fclose($c);
        PHP;
    $hsFile = tempnam(sys_get_temp_dir(), 'hs');
    $proc = proc_open([PHP_BINARY, '-r', $script, (string) $port, $status, $hsFile], [1 => ['pipe', 'w']], $pipes);
    fgets($pipes[1]);

    $reply = (new MinecraftPing())->ping('127.0.0.1', $port, 'play.example.com');
    proc_close($proc);

    expect($reply['players'])->toBe(['online' => 1, 'max' => 5]);
    $hs = hex2bin((string) file_get_contents($hsFile));
    expect($hs)->toContain("\x10play.example.com" . pack('n', $port) . "\x01");   // hostname, port and next state
    expect(MinecraftPing::plainText(['text' => "\u{00A7}lhi", 'extra' => [' ', ['text' => 'there']]]))->toBe('hi there');

    expect(fn () => (new MinecraftPing())->ping('127.0.0.1', $port, 'gone.example.com', 0.5))->toThrow(RuntimeException::class);
});
