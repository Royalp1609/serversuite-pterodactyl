<?php

use ServerSuite\Services\CrashHelpService;
use ServerSuite\Services\ErrorLogService;
use ServerSuite\Services\GameDetectionService;
use ServerSuite\Support\LogErrors;
use ServerSuite\Tests\Fakes\FakeFiles;

/**
 * #87 (Pelican #41/#91): the Errors tab. Fixtures are logs taken off real
 * Rust (Oxide, Carbon) and Valheim (BepInEx) test servers with broken test
 * plugins installed.
 */

function errorsFixture(string $name): string
{
    return (string) file_get_contents(__DIR__ . '/../Fixtures/' . $name);
}

/** @param list<array<string, mixed>> $groups */
function errorsGroup(array $groups, string $start): array
{
    foreach ($groups as $g) {
        if (str_starts_with($g['message'], $start)) {
            return $g;
        }
    }
    throw new RuntimeException("no group starting \"{$start}\"");
}

function errorsServer(string $uuid, string $name, array $variables): object
{
    return (object) [
        'uuid' => $uuid, 'egg_id' => 0,
        'egg' => (object) ['uuid' => null, 'name' => $name, 'docker_images' => [], 'startup' => '', 'script_install' => '', 'nest' => null],
        'startup' => '', 'image' => null,
        'variables' => collect($variables)->map(fn ($v, $k) => (object) ['env_variable' => $k, 'server_value' => null, 'default_value' => $v])->values(),
    ];
}

beforeEach(function () {
    GameDetectionService::flush();
    $this->files = new FakeFiles();
    $this->svc = new ErrorLogService($this->files, new CrashHelpService($this->files));
});

it('groups a Minecraft log and names the plugin', function () {
    $log = "[12:00:05 ERROR]: Could not pass event PlayerJoinEvent to Essentials v2.21.0\njava.lang.NullPointerException: boom\n"
        . "[12:01:05 ERROR]: Could not pass event PlayerJoinEvent to Essentials v2.21.0\njava.lang.NullPointerException: boom\n";
    $this->files->put('/logs/latest.log', $log);

    $r = $this->svc->report('mc', false);
    expect($r['files'])->toBe(['logs/latest.log'])
        ->and($r['groups'])->toHaveCount(1)
        ->and($r['groups'][0]['count'])->toBe(2)
        ->and($r['groups'][0]['mod']['mod_name'])->toBe('Essentials')
        ->and($r['notice'])->toBeNull();
});

it('names the jar Paper could not load, not its loader class (#175)', function () {
    $log = "[20:49:41 ERROR]: [ModernPluginLoadingStrategy] Could not load plugin 'worldedit-bukkit-7.4.6.jar' in folder 'plugins'\n"
        . "java.lang.UnsupportedClassVersionError: com/sk89q/worldedit/bukkit/WorldEditPlugin has been compiled by a more recent version of the Java Runtime (class file version 69.0)\n"
        . "[20:49:42 ERROR]: Could not load 'plugins/Old-Plugin_v1.2.jar' in folder 'plugins'\n";
    $this->files->put('/logs/latest.log', $log);

    $groups = collect($this->svc->report('mc', false)['groups']);
    $we = $groups->first(fn ($g) => str_contains($g['message'], 'worldedit'));
    $old = $groups->first(fn ($g) => str_contains($g['message'], 'Old-Plugin'));
    expect($we['culprit']['file'])->toBe('worldedit-bukkit-7.4.6.jar')
        ->and($we['culprit']['name'])->toBe('worldedit')
        ->and($we['mod']['mod_name'])->toBe('worldedit')
        ->and($old['culprit']['file'])->toBe('Old-Plugin_v1.2.jar')
        ->and($old['culprit']['name'])->toBe('Old-Plugin');
});

it('names the Oxide plugin for compile, timer and hook errors and marks missing members', function () {
    $g = LogErrors::parse(errorsFixture('rust-oxide.txt'), true, [], 'oxide');

    expect($g)->toHaveCount(3)
        ->and(errorsGroup($g, 'Error while compiling')['culprit']['name'])->toBe('SSBrokenTest')
        ->and(errorsGroup($g, 'Failed to run a 1.00 timer')['culprit']['name'])->toBe('SSThrowTest')
        ->and(errorsGroup($g, "Failed to call hook 'Fail'")['exception'])->toBe('MissingMethodException: Method not found: void BasePlayer.ChatMessage(string)')
        ->and(errorsGroup($g, "Failed to call hook 'Fail'")['framework'])->toBeTrue()
        ->and(errorsGroup($g, 'Error while compiling')['framework'])->toBeFalse();
});

it('joins a Carbon compile error and names BepInEx plugins without the Unity noise', function () {
    $carbon = LogErrors::parse(errorsFixture('rust-carbon.txt'), false, [], 'carbon');
    expect(array_column(array_column($carbon, 'culprit'), 'name'))->toBe(['SS Throw Test', 'SSBrokenTest'])
        ->and(errorsGroup($carbon, 'Failed compiling')['detail'])->toContain("Cannot implicitly convert type 'string' to 'int' [CS0029]");

    $bep = LogErrors::parse(errorsFixture('valheim-bepinex.txt'), true, [], 'bepinex');
    expect($bep)->toHaveCount(4)
        ->and(errorsGroup($bep, 'Could not load [SS Dep Test')['culprit']['name'])->toBe('SS Dep Test')
        ->and(errorsGroup($bep, 'InvalidOperationException')['culprit'])->toMatchArray(['name' => 'SSThrowTest', 'strength' => 'weak'])
        ->and(implode("\n", array_column($bep, 'message')))->not->toContain('AsyncResourceUpload');
});

it('explains framework breakage for two plugins or around force-wipe day', function () {
    $two = "15:33 [Error] Failed to call hook 'OnPlayerChat' on plugin 'Kits v4.4.0' (MissingMethodException: Method not found: x)\n"
        . "15:34 [Error] Failed to call hook 'OnEntityDeath' on plugin 'Economics v3.9.0' (MissingFieldException: Field not found: y)\n";
    $one = explode("\n", $two)[0] . "\n";

    expect(LogErrors::frameworkBreak(LogErrors::parse($two, true, [], 'oxide'), new DateTimeImmutable('2026-10-13')))->toBe(['plugins' => ['Economics', 'Kits'], 'wipe_day' => false])
        ->and(LogErrors::frameworkBreak(LogErrors::parse($one, true, [], 'oxide'), new DateTimeImmutable('2026-10-13')))->toBeNull()
        ->and(LogErrors::frameworkBreak(LogErrors::parse($one, true, [], 'oxide'), new DateTimeImmutable('2026-11-05 20:00'))['wipe_day'])->toBeTrue();
});

it('reads the log of the framework the Rust server runs, and Valheim\'s BepInEx log', function () {
    $games = app(GameDetectionService::class);
    $carbon = errorsServer('srv-carbon', 'Rust', ['SRCDS_APPID' => '258550', 'FRAMEWORK' => 'carbon']);
    $oxide = errorsServer('srv-oxide', 'Rust', ['SRCDS_APPID' => '258550', 'FRAMEWORK' => 'oxide']);
    $valheim = errorsServer('srv-v', 'Valheim BepINex', ['SRCDS_APPID' => '896660']);

    expect($games->logSources($carbon))->toBe([['path' => '/carbon/logs/Carbon.Core.log', 'format' => 'carbon']])
        ->and($games->logSources($oxide))->toBe([['path' => '/oxide/logs', 'newest' => '/^oxide_[\d-]+\.txt$/', 'format' => 'oxide']])
        ->and($games->logSources($valheim))->toBe([['path' => '/BepInEx/LogOutput.log', 'format' => 'bepinex']]);

    $this->files->put('/oxide/logs/oxide_2026-10-28.txt', "15:33 [Error] Error while compiling OldPlugin: x\n");
    $this->files->put('/oxide/logs/oxide_2026-10-29.txt', errorsFixture('rust-oxide.txt'));
    $this->files->put('/carbon/logs/Carbon.Core.log', errorsFixture('rust-carbon.txt'));

    $r = $this->svc->report('srv-oxide', true, $games->logSources($oxide));
    expect($r['files'])->toBe(['oxide/logs/oxide_2026-10-29.txt'])
        ->and(array_column($r['groups'], 'message'))->not->toContain('Error while compiling OldPlugin: x');

    expect($this->svc->report('srv-carbon', true, $games->logSources($carbon))['files'])->toBe(['carbon/logs/Carbon.Core.log']);
});

it('acknowledges what is listed now and shows only what happens after', function () {
    $err = fn (string $t, string $msg) => "[{$t} ERROR]: {$msg}\njava.lang.IllegalStateException: x\n";
    $this->files->put('/logs/latest.log', $err('12:00:05', 'Boom A') . $err('12:00:06', 'Boom B'));
    $this->files->put('/crash-reports/crash-old.txt', "---- Minecraft Crash Report ----\n");
    $this->files->mtimes['/crash-reports/crash-old.txt'] = now()->subHour()->toIso8601String();

    $r = $this->svc->withAck('mc', $this->svc->report('mc', false));
    expect($r['new_count'])->toBe(3)->and($r['ack'])->toBeNull();

    $this->svc->acknowledge('mc');
    $r = $this->svc->withAck('mc', $this->svc->report('mc', false));
    expect($r['new_count'])->toBe(0)
        ->and(collect($r['groups'])->every(fn ($g) => $g['acknowledged']))->toBeTrue()
        ->and($r['crashes'][0]['acknowledged'])->toBeTrue();

    // B happens again, C is new, and a new crash report is written.
    $this->files->put('/logs/latest.log', $err('12:00:05', 'Boom A') . $err('12:00:06', 'Boom B') . $err('12:05:00', 'Boom B') . $err('12:06:00', 'Boom C'));
    $this->files->put('/crash-reports/crash-new.txt', "---- Minecraft Crash Report ----\n");
    $this->files->mtimes['/crash-reports/crash-new.txt'] = now()->addMinute()->toIso8601String();
    $this->svc->forget('mc');
    $r = $this->svc->withAck('mc', $this->svc->report('mc', false));
    $acked = collect($r['groups'])->mapWithKeys(fn ($g) => [$g['message'] => $g['acknowledged']])->all();
    expect($acked)->toBe(['Boom C' => false, 'Boom B' => false, 'Boom A' => true] + $acked)
        ->and(collect($r['crashes'])->mapWithKeys(fn ($c) => [$c['name'] => $c['acknowledged']])->all())->toBe(['crash-new.txt' => false, 'crash-old.txt' => true])
        ->and($r['new_count'])->toBe(3);

    $this->svc->unacknowledge('mc');
    expect($this->svc->withAck('mc', $this->svc->report('mc', false))['ack'])->toBeNull();
});

it('treats the same error in a new log after a restart as new', function () {
    $this->files->put('/logs/latest.log', "[12:00:05 ERROR]: Boom A\n");
    $this->svc->acknowledge('mc');

    $this->files->put('/logs/latest.log', "[18:30:00 ERROR]: Boom A\n");
    $this->svc->forget('mc');
    expect($this->svc->withAck('mc', $this->svc->report('mc', false))['groups'][0]['acknowledged'])->toBeFalse();
});
