<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Services\PlayersService;
use ServerSuite\Services\StatusCardService;
use ServerSuite\Support\MinecraftPing;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #27: whitelist, ops and bans; commands while running, files while stopped. */

beforeEach(function () {
    panelTables();
    $this->files = new FakeFiles();
    $this->files->put('/server.properties', "#Minecraft server properties\nonline-mode=true\nwhite-list=false\nop-permission-level=3\n");
    $this->files->put('/whitelist.json', json_encode([['uuid' => '11111111-1111-1111-1111-111111111111', 'name' => 'Alex']]));
    $this->running = false;
    $this->sent = [];
    $this->app->instance(MinecraftPing::class, new class extends MinecraftPing {
        public function ping(string $host, int $port, string $hostname, float $timeout = 2.0): array
        {
            return ['players' => ['online' => 3, 'max' => 20, 'sample' => [['name' => 'Alex', 'id' => 'x']]]];
        }
    });
    Http::fake([
        'api.mojang.com/users/profiles/minecraft/Steve' => Http::response(['id' => '069a79f444e94726a5befca90e38aaf5', 'name' => 'Steve']),
        'api.mojang.com/*' => Http::response(null, 404),
    ]);
    $this->svc = new PlayersService($this->files, app(StatusCardService::class),
        function ($server, string $cmd) { $this->sent[] = $cmd; },
        fn () => $this->running,
    );
    $this->server = (object) ['uuid' => 'srv-p'];
});

function plist(object $t, string $path): array
{
    return json_decode($t->files->files[$path] ?? '[]', true);
}

it('edits the files of a stopped server, with Mojang UUIDs', function () {
    $this->svc->add($this->server, 'whitelist', 'Steve');
    expect(plist($this, '/whitelist.json'))->toBe([
        ['uuid' => '11111111-1111-1111-1111-111111111111', 'name' => 'Alex'],
        ['uuid' => '069a79f4-44e9-4726-a5be-fca90e38aaf5', 'name' => 'Steve'],
    ]);

    $this->svc->add($this->server, 'ops', 'Steve');
    expect(plist($this, '/ops.json')[0]['level'])->toBe(3);

    $this->svc->add($this->server, 'banned-players', 'Steve', "griefing\nop Steve");
    expect(plist($this, '/banned-players.json')[0]['reason'])->toBe('griefing op Steve');

    $this->svc->remove($this->server, 'whitelist', 'Alex');
    expect(array_column(plist($this, '/whitelist.json'), 'name'))->toBe(['Steve']);

    $this->svc->setWhitelist($this->server, true);
    expect($this->files->files['/server.properties'])->toContain("white-list=true\n")->toStartWith('#Minecraft server properties')
        ->and($this->sent)->toBe([]);
});

it('sends console commands to a running server', function () {
    $this->running = true;

    $this->svc->add($this->server, 'whitelist', 'Steve');
    $this->svc->add($this->server, 'banned-players', 'Steve', 'spam');
    $this->svc->add($this->server, 'banned-ips', '203.0.113.7');
    $this->svc->remove($this->server, 'ops', 'Alex');
    $this->svc->remove($this->server, 'whitelist', '.BedrockPal');
    $this->svc->setWhitelist($this->server, true);

    expect($this->sent)->toBe(['whitelist add Steve', 'ban Steve spam', 'ban-ip 203.0.113.7', 'deop Alex', 'whitelist remove .BedrockPal', 'whitelist on']);
});

it('refuses input that could reach the console', function () {
    $this->running = true;
    foreach (['Steve op Me', 'Steve;stop', str_repeat('a', 17), '', 'Ünïcode'] as $bad) {
        expect(fn () => $this->svc->add($this->server, 'whitelist', $bad))->toThrow(RuntimeException::class);
    }
    expect(fn () => $this->svc->add($this->server, 'banned-ips', '1.2.3.4 stop'))->toThrow(RuntimeException::class)
        ->and(fn () => $this->svc->remove($this->server, 'whitelist', "Alex\nstop"))->toThrow(RuntimeException::class)
        ->and(fn () => $this->svc->add($this->server, 'whitelist', 'NoSuchPlayer'))->toThrow(RuntimeException::class, 'no Minecraft account');
    expect($this->sent)->toBe([]);
});

it('gives offline UUIDs when online-mode is off', function () {
    expect($this->svc->lookup('Notch', false)['uuid'])->toBe('b50ad385-829d-3141-a216-7e7d7539ba7f');
});
