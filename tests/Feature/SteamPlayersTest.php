<?php

use ServerSuite\Services\SteamPlayersService;
use ServerSuite\Tests\Fakes\FakeFiles;

/**
 * #85 (Pelican #89): Rust owners/moderators/bans and Valheim's admin/banned/
 * permitted lists by SteamID. Formats and commands as seen on real servers.
 */

const STEAM_USERS = '/server/rust/cfg/users.cfg';
const STEAM_BANS = '/server/rust/cfg/bans.cfg';
const STEAM_VALHEIM = '/.config/unity3d/IronGate/Valheim/';

beforeEach(function () {
    $this->files = new FakeFiles();
    $this->sent = [];
    $this->running = false;
    $this->svc = new SteamPlayersService($this->files, function ($server, string $c) { $this->sent[] = $c; }, fn () => $this->running);
    $this->server = (object) ['uuid' => 's'];
});

it('sends Rust console commands and saves the cfg while running', function () {
    $this->running = true;

    expect($this->svc->add($this->server, 'rust', 'owners', '76561197960287930', 'Alex "the" Admin', 'co-owner'))->toBe('76561197960287930 added.');
    $this->svc->add($this->server, 'rust', 'bans', 'Steam_76561197960287932', '', 'griefing');
    $this->svc->remove($this->server, 'rust', 'bans', '76561197960287932');

    expect($this->sent)->toBe([
        'ownerid 76561197960287930 "Alex  the  Admin" "co-owner"', 'server.writecfg',
        'banid 76561197960287932 "" "griefing"', 'server.writecfg',
        'unban 76561197960287932', 'server.writecfg',
    ]);
});

it('edits Rust\'s cfg files in its own format while stopped', function () {
    $this->files->put(STEAM_USERS, "ownerid 76561197960287930 \"SS Test Owner\" \"test\"\r\nmoderatorid 76561197960287931 \"SS Test Mod\" \"test\"\r\n");

    expect($this->svc->overview($this->server, 'rust')['lists']['owners'])->toBe([['id' => '76561197960287930', 'name' => 'SS Test Owner', 'reason' => 'test']]);
    expect($this->svc->add($this->server, 'rust', 'moderators', '76561198000000001'))->toBe('76561198000000001 added. It applies when the server starts.');
    $this->svc->remove($this->server, 'rust', 'owners', '76561197960287930');
    $this->svc->add($this->server, 'rust', 'bans', '76561198000000002', 'Griefer', 'raiding');

    expect($this->files->files[STEAM_USERS])->toBe("moderatorid 76561197960287931 \"SS Test Mod\" \"test\"\r\nmoderatorid 76561198000000001 \"\" \"\"\r\n")
        ->and($this->files->files[STEAM_BANS])->toBe("banid 76561198000000002 \"Griefer\" \"raiding\" -1\r\n")
        ->and($this->sent)->toBe([]);
});

it('refuses anything but a SteamID before the console', function () {
    $this->running = true;
    foreach (['Alex', '7656119796028793', '76561197960287930; quit', '12345678901234567'] as $bad) {
        expect(fn () => $this->svc->add($this->server, 'rust', 'owners', $bad))->toThrow(RuntimeException::class);
    }
    expect($this->sent)->toBe([]);
});

it('keeps Valheim\'s header, saves Steam_ IDs and warns about the permitted list', function () {
    $this->files->put(STEAM_VALHEIM . 'adminlist.txt', "// List admin players ID  ONE per line\n76561197960287930\n");

    expect($this->svc->add($this->server, 'valheim', 'admins', '76561198000000001'))->toBe('Steam_76561198000000001 added. It applies when the server starts.');
    $this->svc->remove($this->server, 'valheim', 'admins', 'Steam_76561197960287930');
    expect($this->svc->add($this->server, 'valheim', 'permitted', 'Xbox_2535405290012345'))
        ->toBe('Xbox_2535405290012345 added. It applies when the server starts. From then on only players on the permitted list can join.');

    expect($this->files->files[STEAM_VALHEIM . 'adminlist.txt'])->toBe("// List admin players ID  ONE per line\nSteam_76561198000000001\n")
        ->and($this->files->files[STEAM_VALHEIM . 'permittedlist.txt'])->toBe("// List permitted players ID ONE per line\nXbox_2535405290012345\n");

    $this->running = true;
    expect($this->svc->add($this->server, 'valheim', 'banned', '76561198000000003'))->toContain('Restart the server to apply it')
        ->and($this->sent)->toBe([]);
});

it('refuses changes while the server starts or stops', function () {
    $this->files->put(STEAM_USERS, "ownerid 76561197960287930 \"SS Test Owner\" \"test\"\r\n");

    foreach (['starting' => 'started', 'stopping' => 'stopped'] as $state => $done) {
        $this->running = $state;
        expect(fn () => $this->svc->add($this->server, 'rust', 'owners', '76561198000000001'))->toThrow(RuntimeException::class, "The server is {$state}. Try again once it has {$done}.")
            ->and(fn () => $this->svc->remove($this->server, 'rust', 'owners', '76561197960287930'))->toThrow(RuntimeException::class)
            ->and(fn () => $this->svc->add($this->server, 'valheim', 'banned', '76561198000000001'))->toThrow(RuntimeException::class);
    }

    expect($this->sent)->toBe([])
        ->and($this->files->files[STEAM_USERS])->toBe("ownerid 76561197960287930 \"SS Test Owner\" \"test\"\r\n")
        ->and($this->files->files)->not->toHaveKey(STEAM_VALHEIM . 'bannedlist.txt');
});
