<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Modpacks\MrpackIndex;

/** #141: small items from the 2026-11-11 review that need no panel. */

it('refuses pack URLs with a user name even without a password (item 31)', function () {
    expect(MrpackIndex::allowedUrl('https://user@github.com/a/b.jar'))->toBeFalse()
        ->and(MrpackIndex::allowedUrl('https://user:pw@github.com/a/b.jar'))->toBeFalse()
        ->and(MrpackIndex::allowedUrl('https://github.com/a/b.jar'))->toBeTrue();
});

it('installs a CurseForge file once when the manifest lists it twice (item 32)', function () {
    $manifest = json_encode(['manifestType' => 'minecraftModpack', 'minecraft' => ['version' => '1.20.1'],
        'files' => [['projectID' => 1, 'fileID' => 7, 'required' => true], ['projectID' => 1, 'fileID' => 7, 'required' => true]]]);
    $index = MrpackIndex::fromCurseForge($manifest, ['7' => ['id' => 7, 'fileName' => 'jei.jar', 'downloadUrl' => 'https://edge.forgecdn.net/files/7/jei.jar', 'hashes' => [['value' => str_repeat('a', 40), 'algo' => 1]]]]);

    expect(array_column($index->files, 'action'))->toBe(['install', 'invalid'])
        ->and($index->files[1]['reason'])->toBe('listed twice in the pack');
});

it('allows ".." inside a name but not as a path segment (item 38)', function () {
    expect(\ServerSuite\Services\ConfigFilesService::safe('/config/mod..settings.yml'))->toBe('/config/mod..settings.yml')
        ->and(fn () => \ServerSuite\Services\ConfigFilesService::safe('/config/../server.jar'))->toThrow(InvalidArgumentException::class, 'Invalid path')
        ->and(fn () => \ServerSuite\Services\ConfigFilesService::safe('/config/..'))->toThrow(InvalidArgumentException::class, 'Invalid path');
});

it('opens and saves only config file types (item 37)', function () {
    expect(\ServerSuite\Services\ConfigFilesService::safeFile('/plugins/Essentials/config.yml'))->toBe('/plugins/Essentials/config.yml')
        ->and(fn () => \ServerSuite\Services\ConfigFilesService::safeFile('/plugins/Essentials.jar'))->toThrow(InvalidArgumentException::class, 'Only config files');
});

it('gives each search its own cache key (item 82)', function () {
    Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => []])]);
    $d = new \ServerSuite\Drivers\ModrinthDriver();
    $d->search('xpaper', null, null, 20);
    $d->search('x', 'paper', null, 20);

    Http::assertSentCount(2);
});

it('skips a malformed search hit instead of failing the search (item 79)', function () {
    Http::fake(['api.modrinth.com/v2/search*' => Http::response(['hits' => [
        ['slug' => 'no-id', 'title' => 'No id'],
        ['project_id' => 'P1', 'slug' => 'ok', 'title' => 'OK', 'display_categories' => ['paper']],
    ]])]);

    $r = (new \ServerSuite\Drivers\ModrinthDriver())->search('ok', null, null, 20);
    expect($r)->toHaveCount(1)->and($r[0]->id)->toBe('P1')
        ->and($r[0]->pageUrl)->toBe('https://modrinth.com/plugin/ok');   // item 78
});

it('remembers a missing project and names the owner, not the team id (items 77, 80)', function () {
    Http::fake([
        'api.modrinth.com/v2/project/gone' => Http::response(['error' => 'not_found'], 404),
        'api.modrinth.com/v2/project/P1/members' => Http::response([['role' => 'Member', 'user' => ['username' => 'helper']], ['role' => 'Owner', 'is_owner' => true, 'user' => ['username' => 'author1']]]),
        'api.modrinth.com/v2/project/P1' => Http::response(['id' => 'P1', 'slug' => 'mod1', 'title' => 'Mod 1', 'team' => 'TEAMID', 'loaders' => ['fabric']]),
    ]);
    $d = new \ServerSuite\Drivers\ModrinthDriver();

    expect($d->get('gone'))->toBeNull()->and($d->get('gone'))->toBeNull();
    Http::assertSentCount(1);

    $p = $d->get('P1');
    expect($p->author)->toBe('author1')->and($p->pageUrl)->toBe('https://modrinth.com/mod/mod1');
});

it('does not cache an empty game version list after a failure (item 81)', function () {
    Http::fakeSequence('api.modrinth.com/v2/tag/game_version')
        ->push('down', 503)
        ->push('down', 503)   // the driver retries once
        ->push([['version' => '1.21.4', 'version_type' => 'release']]);
    $d = new \ServerSuite\Drivers\ModrinthDriver();

    expect(fn () => $d->gameVersionTags())->toThrow(\ServerSuite\Exceptions\RepositoryException::class)
        ->and($d->gameVersionTags())->toBe(['1.21.4']);
});

it('reads pre-release names as versions, not ranges, and compares every part (item 85)', function () {
    $svc = app(\ServerSuite\Services\ModManagerService::class);
    $covers = fn (string $e, string $v) => (fn () => $this->versionCovers($e, $v))->call($svc);

    expect($covers('1.20-1.21', '1.20.6'))->toBeTrue()
        ->and($covers('1.20-1.21', '1.21.4'))->toBeFalse()
        ->and($covers('1.21-rc1', '1.21.4'))->toBeFalse()
        ->and($covers('1.20.1-1.20.4', '1.20.4'))->toBeTrue();
});

it('lists each line\'s first release in the fallback versions (item 84)', function () {
    $svc = app(\ServerSuite\Services\ModManagerService::class);
    $list = (fn () => $this->fallbackGameVersions())->call($svc);

    expect($list)->toContain('1.21')->toContain('1.20')->toContain('1.21.11')
        ->and(array_search('1.21', $list))->toBeGreaterThan(array_search('1.21.1', $list));
});

it('keeps the line ending of a CRLF server.properties (item 106)', function () {
    $files = new \ServerSuite\Tests\Fakes\FakeFiles();
    $files->put('/server.properties', "a=1\r\ntexturepack-required=false\r\nb=2\r\n");
    (new \ServerSuite\Services\BedrockAddonService($files))->setTexturepackRequired('s', true);

    expect($files->files['/server.properties'])->toBe("a=1\r\ntexturepack-required=true\r\nb=2\r\n");
});

it('repairs a reason with invalid UTF-8 instead of dropping it (item 112)', function () {
    $svc = (new ReflectionClass(\ServerSuite\Services\PlayersService::class))->newInstanceWithoutConstructor();
    $reason = (fn (string $r) => $this->reason($r))->call($svc, "griefing \xFF spawn");

    expect($reason)->toStartWith('griefing')->toEndWith('spawn');
});

it('shares one unpack limit across nested packs (item 105)', function () {
    $dir = sys_get_temp_dir() . '/ss-nested-' . bin2hex(random_bytes(3));
    @mkdir($dir);
    $pack = function (string $path, string $uuid) {
        $z = new ZipArchive();
        $z->open($path, ZipArchive::CREATE);
        $z->addFromString('manifest.json', json_encode(['format_version' => 2, 'header' => ['name' => 'P', 'uuid' => $uuid, 'version' => [1, 0, 0]], 'modules' => [['type' => 'data', 'uuid' => $uuid, 'version' => [1, 0, 0]]]]));
        $z->addFromString('pad.bin', random_bytes(4000));   // random: doesn't compress
        $z->close();
    };
    $outer = new ZipArchive();
    $outer->open("{$dir}/addon.mcaddon", ZipArchive::CREATE);
    foreach (['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222', '33333333-3333-4333-8333-333333333333'] as $i => $u) {
        $pack("{$dir}/p{$i}.mcpack", $u);
        $outer->addFile("{$dir}/p{$i}.mcpack", "p{$i}.mcpack");
    }
    $outer->close();

    expect(fn () => \ServerSuite\Support\BedrockArchive::packs("{$dir}/addon.mcaddon", 20000))->toThrow(RuntimeException::class, 'unpacks to more than')
        ->and(\ServerSuite\Support\BedrockArchive::packs("{$dir}/addon.mcaddon", 100000))->toHaveCount(3);
});
