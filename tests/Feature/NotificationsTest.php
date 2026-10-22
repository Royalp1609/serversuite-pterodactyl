<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ServerSuite\Console\Commands\WatchCrashesCommand;
use ServerSuite\Jobs\SendNotificationJob;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\CrashHelpService;
use ServerSuite\Services\Notifier;
use ServerSuite\Tests\Fakes\FakeFiles;

/** #26: notifications to Discord and ntfy, per server or the admin default. */

const HOOK = 'https://discord.com/api/webhooks/123456/abcDEF-secret_token';

beforeEach(function () {
    panelTables();
    $this->n = app(Notifier::class);
});

it('stores server targets encrypted and never serialises them', function () {
    $this->n->saveServer('srv-n', [['type' => 'discord', 'url' => HOOK]], []);

    $raw = (string) DB::table('mod_manager_settings')->where('server_uuid', 'srv-n')->value('notify');
    expect($raw)->not->toContain('discord.com')
        ->and($this->n->forServer('srv-n')['targets'][0]['url'])->toBe(HOOK)
        ->and(ModManagerSetting::forServer('srv-n')->toArray())->not->toHaveKey('notify')
        ->and(Notifier::label(['type' => 'discord', 'url' => HOOK]))->toBe('Discord webhook …_token');
});

it('keeps owner targets on the public internet; the admin default may be local', function () {
    foreach ([
        [['type' => 'discord', 'url' => 'https://evil.example/api/webhooks/1/x'], 'Discord webhook URL'],
        [['type' => 'ntfy', 'server' => 'http://ntfy.sh', 'topic' => 'x'], 'https://'],
        [['type' => 'ntfy', 'server' => 'https://10.0.0.5', 'topic' => 'x'], 'private network'],
        [['type' => 'ntfy', 'server' => 'https://127.0.0.1:8080', 'topic' => 'x'], 'private network'],
        [['type' => 'ntfy', 'server' => 'https://[::1]', 'topic' => 'x'], 'private network'],
        [['type' => 'ntfy', 'server' => 'https://ntfy.sh', 'topic' => 'a b'], 'topic'],
    ] as [$t, $msg]) {
        expect(fn () => $this->n->saveServer('srv-n', [$t], []))->toThrow(InvalidArgumentException::class, $msg);
    }

    $this->n->saveDefaults([['type' => 'ntfy', 'server' => 'http://192.168.5.10:8080', 'topic' => 'panel']], []);
    expect($this->n->forServer('srv-n')['targets'][0]['server'])->toBe('http://192.168.5.10:8080');
});

it('queues encrypted jobs once per cooldown, with an hourly cap and event switches', function () {
    Queue::fake();
    $this->n->saveServer('srv-n', [['type' => 'discord', 'url' => HOOK]], ['backup' => false]);

    $this->n->notify('srv-n', 'updates', '2 mod update(s) available', 'A: 1 → 2');
    $this->n->notify('srv-n', 'updates', '2 mod update(s) available', 'A: 1 → 2');
    $this->n->notify('srv-n', 'backup', 'Scheduled backup failed', 'x', 'error');
    Queue::assertPushed(SendNotificationJob::class, 1);
    expect(class_implements(SendNotificationJob::class))->toContain(Illuminate\Contracts\Queue\ShouldBeEncrypted::class);

    for ($i = 0; $i < 40; $i++) {
        $this->n->notify('srv-n', 'subdomain', "claimed {$i}");
    }
    Queue::assertPushed(SendNotificationJob::class, 30);
});

it('formats Discord embeds and ntfy messages', function () {
    Http::fake(['*' => Http::response('', 204)]);
    $msg = ['title' => 'Scheduled backup failed', 'body' => 'Out of slots', 'level' => 'error', 'server' => 'My SMP', 'url' => 'https://panel.example/server/abcd'];

    Notifier::deliver(['type' => 'discord', 'url' => HOOK], $msg, true);
    Notifier::deliver(['type' => 'ntfy', 'server' => 'https://ntfy.sh', 'topic' => 'my-smp', 'token' => 'tk_1'], $msg, false);

    Http::assertSent(fn (Request $r) => $r->url() === HOOK && $r['embeds'][0]['color'] === 0xEF4444 && $r['allowed_mentions'] === ['parse' => []]);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://ntfy.sh/my-smp' && $r->body() === 'Out of slots'
        && $r->hasHeader('Title', 'My SMP: Scheduled backup failed') && $r->hasHeader('Authorization', 'Bearer tk_1'));
});

it('retries a failing target on its own', function () {
    Queue::fake();
    Http::fake(['discord.com/*' => Http::response('', 204), 'ntfy.sh/*' => Http::response('', 500)]);

    (new SendNotificationJob([['type' => 'discord', 'url' => HOOK], ['type' => 'ntfy', 'server' => 'https://ntfy.sh', 'topic' => 't', 'token' => '']],
        ['title' => 't', 'body' => 'b', 'level' => 'info', 'server' => null, 'url' => null], false))->handle();

    Queue::assertPushed(SendNotificationJob::class, fn ($j) => $j->round === 2 && count($j->targets) === 1 && $j->targets[0]['type'] === 'ntfy');
});

it('reports a crash after a mod change once', function () {
    Queue::fake();
    Pterodactyl\Models\Server::query()->create(['uuid' => 'srv-n']);
    $this->n->saveDefaults([['type' => 'discord', 'url' => HOOK]], []);
    $files = new FakeFiles();
    $files->put('/crash-reports/crash-1.txt', "---- Minecraft Crash Report ----\nDescription: Exception in server tick loop\n");
    $files->mtimes['/crash-reports/crash-1.txt'] = now()->addMinute()->toIso8601String();
    $crash = new CrashHelpService($files);
    $crash->recordChange('srv-n', 'install Sodium', ['uuid' => 'b1', 'name' => 'Before Sodium']);

    (new WatchCrashesCommand())->handle($crash, $this->n);
    (new WatchCrashesCommand())->handle($crash, $this->n);

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn ($j) => $j->message['title'] === 'Crashed after: install Sodium' && str_contains($j->message['body'], 'Before Sodium'));
});
