<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Exceptions\ModpackException;
use ServerSuite\Jobs\InstallModpackJob;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Modpack;
use ServerSuite\Models\ServerMod;
use ServerSuite\Modpacks\MrpackIndex;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Services\ModpackInstaller;
use ServerSuite\Services\ModpackService;
use ServerSuite\Services\ReconciliationService;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Support\Data\RotationResult;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #23: Modrinth .mrpack install and update, end to end against a fake Wings
 * and a faked Modrinth API, through the real job. Ported from the Pelican
 * plugin's ModpackTest (pelican-server-suite#16).
 */

const MP_CDN = 'https://cdn.modrinth.com/data';

beforeEach(function () {
    Queue::fake();

    $this->server = (object) ['uuid' => 'srv-mp'];
    ModManagerSetting::forServer('srv-mp')->forceFill(['loader' => 'fabric', 'game_version' => '1.20.1'])->save();

    $this->wings = new FakeWings();
    app()->instance(DaemonFileClient::class, $this->wings);

    $this->power = 'running';
    $this->installing = 0;   // polls left until a reinstall finishes
    $this->ops = Mockery::mock(PanelServerOps::class);
    $this->ops->shouldReceive('runtimeState')->andReturnUsing(function () {
        $install = $this->installing > 0 ? 'installing' : null;
        $this->installing = max(0, $this->installing - 1);

        return ['power' => $this->power, 'install' => $install];
    })->byDefault();
    $this->ops->shouldReceive('power')->with('srv-mp', 'stop')->andReturnUsing(function () {
        $this->power = 'offline';
    });
    $this->ops->shouldReceive('power')->with('srv-mp', 'start');   // #69: started again at the end
    $this->ops->shouldReceive('serverBackupLimit')->andReturn(5);
    $this->ops->shouldReceive('listBackups')->andReturn([new BackupRef('b-1', 'Pre-modpack', 10, false, true, now()->toIso8601String(), now()->toIso8601String())]);
    app()->instance(PanelServerOps::class, $this->ops);

    $this->backupNames = [];
    $this->rotation = Mockery::mock(BackupRotationService::class);
    $this->rotation->shouldReceive('backupNow')->andReturnUsing(function (string $uuid, string $name) {
        $this->backupNames[] = $name;

        return RotationResult::rotated(new BackupRef('b-1', $name, 0, false, false), [], 5);
    })->byDefault();
    app()->instance(BackupRotationService::class, $this->rotation);

    $this->packVersions = [];   // version id => Modrinth version json
    $this->packZips = [];       // mrpack url => zip bytes (what the panel downloads)
    $this->knownMods = [];      // sha1 => mod
    Http::fake(fn (Request $r) => mpModrinth($this, $r));
});

function mpModrinth(object $t, Request $r)
{
    $url = $r->url();
    $path = parse_url($url, PHP_URL_PATH);

    if (isset($t->packZips[$url])) {
        return Http::response($t->packZips[$url]);
    }
    if (preg_match('#^/v2/version/([^/]+)$#', $path, $m) && isset($t->packVersions[$m[1]])) {
        return Http::response($t->packVersions[$m[1]]);
    }
    if ($path === '/v2/search') {
        return Http::response(['hits' => [[
            'project_id' => 'PACK', 'slug' => 'test-pack', 'title' => 'Test Pack', 'description' => 'A pack for tests',
            'author' => 'tester', 'downloads' => 1234, 'icon_url' => null, 'versions' => ['1.20.1'], 'categories' => ['fabric'],
        ]]]);
    }
    if ($path === '/v2/project/PACK') {
        return Http::response(['id' => 'PACK', 'slug' => 'test-pack', 'title' => 'Test Pack', 'icon_url' => null]);
    }
    if ($path === '/v2/project/PACK/version') {
        return Http::response(array_values($t->packVersions));
    }
    if ($path === '/v2/version_files' && $r->method() === 'POST') {
        $out = [];
        foreach ((array) $r['hashes'] as $h) {
            if (isset($t->knownMods[$h])) {
                $k = $t->knownMods[$h];
                $out[$h] = ['id' => $k['version'], 'project_id' => $k['project'], 'version_number' => $k['version'], 'files' => [], 'dependencies' => []];
            }
        }

        return Http::response($out);
    }
    if ($path === '/v2/projects') {
        $ids = json_decode((string) $r['ids'], true);
        $names = collect($t->knownMods)->keyBy('project');

        return Http::response(array_map(fn ($id) => ['id' => $id, 'slug' => strtolower($id), 'title' => $names[$id]['name'] ?? $id], $ids));
    }

    return Http::response(['error' => 'not faked: ' . $r->method() . ' ' . $url], 404);
}

/** @param array<string, string> $entries name => bytes; $modes name => unix mode */
function mpZip(array $entries, array $modes = []): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'mrpack');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, (0100000 | ($modes[$name] ?? 0644)) << 16);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

/**
 * Publish a pack version: the zip goes to Modrinth (for the preview) and to
 * Wings' remote (for the install); its files go to Wings' remote.
 *
 * @param array<string, array> $files  path => spec (bytes, url, server, mod, missing)
 */
function mpPublish(object $t, string $versionId, array $files, array $zipExtra = [], array $deps = ['minecraft' => '1.20.1', 'fabric-loader' => '0.16.5'], array $modes = []): string
{
    $entries = [];
    foreach ($files as $path => $spec) {
        $bytes = $spec['bytes'] ?? "jar:{$path}:{$versionId}";
        $url = $spec['url'] ?? MP_CDN . '/' . md5($path) . "/versions/{$versionId}/" . basename($path);
        $entry = ['path' => $path, 'hashes' => ['sha1' => sha1($bytes), 'sha512' => hash('sha512', $bytes)], 'downloads' => [$url], 'fileSize' => strlen($bytes)];
        if (isset($spec['server'])) {
            $entry['env'] = ['client' => 'required', 'server' => $spec['server']];
        }
        $entries[] = $entry;
        if (! isset($spec['missing'])) {
            $t->wings->remote[$url] = $bytes;
        }
        if (isset($spec['mod'])) {
            $t->knownMods = $t->knownMods + [sha1($bytes) => ['project' => $spec['mod'], 'version' => "{$spec['mod']}-{$versionId}", 'name' => "Mod {$spec['mod']}"]];
        }
    }
    $index = json_encode(['formatVersion' => 1, 'game' => 'minecraft', 'versionId' => $versionId, 'name' => 'Test Pack', 'files' => $entries, 'dependencies' => $deps]);

    $zipBytes = mpZip(['modrinth.index.json' => $index] + $zipExtra, $modes);
    $url = MP_CDN . "/PACK/versions/{$versionId}/test-pack.mrpack";
    $t->packZips = [$url => $zipBytes] + $t->packZips;
    $t->wings->remote[$url] = $zipBytes;
    $t->packVersions = $t->packVersions + [$versionId => [
        'id' => $versionId, 'project_id' => 'PACK', 'version_number' => $versionId, 'name' => $versionId,
        'game_versions' => [$deps['minecraft']], 'loaders' => ['fabric'], 'version_type' => 'release',
        'files' => [['url' => $url, 'filename' => 'test-pack.mrpack', 'primary' => true, 'size' => strlen($zipBytes), 'hashes' => ['sha1' => sha1($zipBytes)]]],
    ]];

    return $index;
}

function mpV1(object $t): void
{
    mpPublish($t, 'v1', [
        'mods/a.jar'          => ['mod' => 'MODA'],
        'mods/b.jar'          => ['mod' => 'MODB'],
        'mods/client.jar'     => ['server' => 'unsupported'],
        'config/c.json'       => [],
        'resourcepacks/x.zip' => ['url' => 'https://evil.example.com/x.zip'],
    ], [
        'overrides/config/a.toml'        => 'client value',
        'server-overrides/config/a.toml' => 'server value',
        'overrides/mods/extra.jar'       => 'extra jar',
    ]);
}

function mpSvc(): ModpackService
{
    return app(ModpackService::class);
}

/** Run the queued job until the install stops. */
function mpRun(): Modpack
{
    $pack = Modpack::forServer('srv-mp');
    for ($i = 0; $i < 50 && $pack->refresh()->isRunning(); $i++) {
        (new InstallModpackJob($pack->id))->handle(app(ModpackInstaller::class));
    }
    expect($pack->isRunning())->toBeFalse('install did not finish');

    return $pack;
}

// --- index ---------------------------------------------------------------

it('classifies every file in the index and refuses bad packs', function () {
    $file = fn (string $path, array $extra = []) => $extra + ['path' => $path, 'hashes' => ['sha1' => str_repeat('a', 40)], 'downloads' => [MP_CDN . '/x/y.jar'], 'fileSize' => 3];
    $index = MrpackIndex::parse(json_encode(['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1.20.1', 'neoforge' => '20.4.1'], 'files' => [
        $file('mods/ok.jar'),
        $file('../escape.jar'),
        $file('/etc/passwd'),
        $file('mods\\win.jar'),
        $file('.serversuite/modpack/x'),
        $file('mods/client.jar', ['env' => ['client' => 'required', 'server' => 'unsupported']]),
        $file('mods/http.jar', ['downloads' => ['http://cdn.modrinth.com/x.jar']]),
        $file('mods/other.jar', ['downloads' => ['https://example.com/x.jar']]),
        ['path' => 'mods/nohash.jar', 'downloads' => [MP_CDN . '/x.jar']],
    ]]));

    expect([$index->loader, $index->loaderVersion, $index->minecraft])->toBe(['neoforge', '20.4.1', '1.20.1'])
        ->and(array_column($index->files, 'action'))->toBe(['install', 'invalid', 'invalid', 'invalid', 'invalid', 'client_only', 'blocked', 'blocked', 'invalid']);

    foreach ([
        ['formatVersion' => 2, 'game' => 'minecraft'],
        ['formatVersion' => 1, 'game' => 'other'],
        ['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => []],
        ['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1', 'forge' => '1', 'fabric-loader' => '1']],
        ['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1'], 'files' => [$file('mods/a.jar'), $file('mods/a.jar')]],
    ] as $bad) {
        expect(fn () => MrpackIndex::parse(json_encode($bad)))->toThrow(ModpackException::class);
    }
});

// --- preview -------------------------------------------------------------

it('previews what an install will do', function () {
    mpV1($this);

    $p = mpSvc()->preview($this->server, 'PACK', 'v1');

    expect($p['blockers'])->toBe([])
        ->and($p['counts'])->toBe(['install' => 3, 'unchanged' => 0, 'remove' => 0, 'replace' => 0, 'overrides' => 2, 'skipped' => 2])
        ->and(array_column($p['skipped'], 'path'))->toEqualCanonicalizing(['mods/client.jar', 'resourcepacks/x.zip'])
        ->and($p['requires'])->toBe(['loader' => 'fabric', 'loader_version' => '0.16.5', 'minecraft' => '1.20.1'])
        ->and($p['pack']['page_url'])->toBe('https://modrinth.com/modpack/test-pack')
        ->and(collect($p['plan']['install'])->firstWhere('path', 'mods/a.jar')['mod']['name'])->toBe('Mod MODA');
});

it('tells a server on another loader or version what to switch to, and refuses to start', function () {
    mpV1($this);
    ModManagerSetting::forServer('srv-mp')->forceFill(['loader' => 'forge', 'game_version' => '1.21.4'])->save();

    expect(mpSvc()->preview($this->server, 'PACK', 'v1')['blockers'][0])->toContain('needs Fabric 1.20.1; the server runs Forge 1.21.4')
        ->toContain('permissions to change its version')
        ->and(fn () => mpSvc()->start($this->server, 'PACK', 'v1', 1))->toThrow(ModpackException::class);
});

// --- install -------------------------------------------------------------

it('installs a pack end to end', function () {
    mpV1($this);
    // The owner's own mod, and their own older copy of a mod the pack has.
    $this->wings->put('/mods/mine.jar', 'mine');
    $this->wings->put('/mods/a-old.jar', 'old a');
    ServerMod::query()->create(['server_uuid' => 'srv-mp', 'source' => 'modrinth', 'source_id' => 'MODA', 'mod_name' => 'Mod MODA', 'installed_version' => 'old', 'target_filename' => 'a-old.jar', 'install_path' => '/mods']);

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    Queue::assertPushed(InstallModpackJob::class);
    $pack = mpRun();

    $f = $this->wings->files;
    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED, (string) $pack->error)
        ->and($pack->failures)->toBe([])
        ->and($f['/mods/a.jar'])->toBe('jar:mods/a.jar:v1')
        ->and($f['/config/c.json'])->toBe('jar:config/c.json:v1')
        ->and($f['/config/a.toml'])->toBe('server value', 'server-overrides win over overrides')
        ->and($f['/mods/extra.jar'])->toBe('extra jar')
        ->and($f['/mods/mine.jar'])->toBe('mine', "the owner's own mod stays")
        ->and($f)->not->toHaveKey('/mods/a-old.jar')
        ->and($f)->not->toHaveKey('/mods/client.jar')
        ->and($f)->not->toHaveKey('/resourcepacks/x.zip')
        ->and(array_filter(array_keys($f), fn ($p) => str_starts_with($p, '/.serversuite')))->toBe([], 'staging removed')
        ->and($this->backupNames)->toBe(["Pre-modpack \u{2192} Test Pack v1"])
        ->and([$pack->name, $pack->version_number, $pack->loader, $pack->minecraft])->toBe(['Test Pack', 'v1', 'fabric', '1.20.1']);
    $this->ops->shouldHaveReceived('power')->with('srv-mp', 'stop')->once();
    $this->ops->shouldHaveReceived('power')->with('srv-mp', 'start')->once();   // #69: it was running

    $mods = ServerMod::forServer('srv-mp')->orderBy('source_id')->get();
    expect($mods->pluck('source_id')->all())->toBe(['MODA', 'MODB'])
        ->and($mods->pluck('modpack_id')->all())->toBe([$pack->id, $pack->id])
        ->and($mods[0]->target_filename)->toBe('a.jar');
});

it('updates: removes what the new version dropped and keeps unchanged files', function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    // v2: a.jar changes, b.jar is dropped, c.json stays the same bytes.
    mpPublish($this, 'v2', [
        'mods/a.jar'    => ['mod' => 'MODA'],
        'config/c.json' => ['bytes' => 'jar:config/c.json:v1'],
    ], ['overrides/config/a.toml' => 'new client value']);
    $this->wings->pulled = [];

    $p = mpSvc()->preview($this->server, 'PACK', 'v2');
    expect($p['current']['same_pack'])->toBeTrue()
        ->and(array_intersect_key($p['counts'], ['install' => 1, 'unchanged' => 1]))->toBe(['install' => 1, 'unchanged' => 1])
        ->and($p['remove'])->toEqualCanonicalizing(['mods/b.jar', 'mods/extra.jar']);

    mpSvc()->start($this->server, 'PACK', 'v2', 1);
    $pack = mpRun();

    $f = $this->wings->files;
    expect($f['/mods/a.jar'])->toBe('jar:mods/a.jar:v2')
        ->and($f)->not->toHaveKey('/mods/b.jar')
        ->and($f)->not->toHaveKey('/mods/extra.jar')
        ->and($f['/config/a.toml'])->toBe('new client value')
        ->and(array_filter($this->wings->pulled, fn ($u) => str_contains($u, 'c.json')))->toBe([], 'unchanged file not downloaded again')
        ->and($pack->version_number)->toBe('v2')
        ->and(ServerMod::forServer('srv-mp')->pluck('source_id')->all())->toBe(['MODA']);
});

it('reports a failed download, and reinstalling fetches only that file', function () {
    mpPublish($this, 'v1', ['mods/a.jar' => ['mod' => 'MODA'], 'mods/b.jar' => ['missing' => true]]);

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    $pack = mpRun();
    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED)
        ->and(array_column($pack->failures, 'path'))->toBe(['mods/b.jar']);

    // The host is back; installing the same version again only fetches b.jar.
    $b = MP_CDN . '/' . md5('mods/b.jar') . '/versions/v1/b.jar';
    $this->wings->remote[$b] = 'jar:mods/b.jar:v1';
    $this->wings->pulled = [];
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    $pack = mpRun();

    expect($pack->failures)->toBe([])
        ->and(array_values(array_filter($this->wings->pulled, fn ($u) => ! str_ends_with($u, '.mrpack'))))->toBe([$b]);
});

it('refuses a pack that changed after review, before any file changes', function () {
    mpV1($this);
    $this->wings->put('/mods/mine.jar', 'mine');
    mpSvc()->start($this->server, 'PACK', 'v1', 1);

    // Wings gets a different archive than the one the user confirmed.
    $url = MP_CDN . '/PACK/versions/v1/test-pack.mrpack';
    $other = mpZip(['modrinth.index.json' => json_encode(['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => ['minecraft' => '1.20.1'], 'files' => []])]);
    $this->wings->remote[$url] = $other;
    $pack = Modpack::forServer('srv-mp');
    $pack->forceFill(['target' => ['mrpack' => ['url' => $url, 'size' => strlen($other)] + $pack->target['mrpack']] + $pack->target])->save();

    $pack = mpRun();

    expect($pack->status)->toBe(Modpack::STATUS_FAILED)
        ->and($pack->error)->toContain('not the one you confirmed')
        ->and(array_keys($this->wings->files))->toBe(['/mods/mine.jar']);
});

it('changes nothing without the backup', function () {
    mpV1($this);
    $this->rotation->shouldReceive('backupNow')->andReturn(RotationResult::failed('limit_reached', 'all backups are locked'));

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    $pack = mpRun();

    expect($pack->status)->toBe(Modpack::STATUS_FAILED)
        ->and($pack->error)->toContain('backup could not be taken')
        ->and($this->wings->pulled)->toBe([]);
});

it('makes override files readable when the zip has no permissions on them', function () {
    // Seen on Pelican: Cobblemon 1.5.2's overrides/mods/slimyfloor jar is
    // mode 000 in the zip; Wings kept it, and Fabric failed "Permission denied".
    mpPublish($this, 'v1', ['mods/a.jar' => []], [
        'overrides/mods/slimyfloor.jar' => 'jar', 'overrides/config/deep/x.toml' => 'x',
    ], modes: ['overrides/mods/slimyfloor.jar' => 0, 'overrides/config/deep/x.toml' => 0]);

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    expect($this->wings->modes['/mods/slimyfloor.jar'])->toBe(0644)
        ->and($this->wings->modes['/config/deep/x.toml'])->toBe(0644);
});

it('records the install for crash help, with the backup', function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    $change = ModManagerSetting::forServer('srv-mp')->last_change;
    expect($change['label'])->toBe('install modpack Test Pack v1')
        ->and($change['backup'])->toBe(['uuid' => 'b-1', 'name' => 'Pre-modpack backup']);
});

it("doesn't list files the pack owns as untracked", function () {
    mpPublish($this, 'v1', ['mods/github.jar' => ['url' => 'https://github.com/x/y/releases/download/1/github.jar']]);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();
    $this->wings->put('/mods/handmade.jar', 'mine');

    $untracked = app(ReconciliationService::class)->reconcile($this->server)['untracked'];

    expect(array_column($untracked, 'filename'))->toBe(['handmade.jar']);
});

// --- only for servers that can run a pack ---------------------------------

it('offers packs on every loader a pack can target, and nowhere else', function () {
    foreach (['fabric', 'quilt', 'forge', 'neoforge'] as $loader) {
        ModManagerSetting::forServer('srv-mp')->forceFill(['loader' => $loader])->save();
        expect(mpSvc()->supports($this->server))->toBeTrue($loader);
    }
    foreach (['paper', 'purpur', 'folia', 'spigot', 'velocity', 'bedrock'] as $loader) {
        ModManagerSetting::forServer('srv-mp')->forceFill(['loader' => $loader])->save();
        expect(mpSvc()->supports($this->server))->toBeFalse($loader);
    }
});

it("searches for packs for this server's loader and version", function () {
    mpSvc()->search($this->server, 'cobble');

    Http::assertSent(function (Request $r) {
        if (parse_url($r->url(), PHP_URL_PATH) !== '/v2/search') {
            return false;
        }
        $facets = json_decode($r['facets'], true);

        return in_array(['categories:fabric'], $facets, true) && in_array(['versions:1.20.1'], $facets, true)
            && in_array(['project_type:modpack'], $facets, true);
    });
});

it('marks versions for another Minecraft version or loader, compatible first', function () {
    mpV1($this);
    mpPublish($this, 'old', ['mods/a.jar' => ['mod' => 'MODA']], [], ['minecraft' => '1.19.2', 'fabric-loader' => '0.14.0']);
    $this->packVersions = $this->packVersions + ['forge' => ['loaders' => ['forge'], 'id' => 'forge'] + $this->packVersions['v1']];

    $versions = collect(mpSvc()->versions('PACK', $this->server))->keyBy('id');

    expect($versions['v1']['compatible'])->toBeTrue()
        ->and($versions['old']['reason'])->toBe('For Minecraft 1.19.2, not 1.20.1')
        ->and($versions['forge']['reason'])->toBe('For Forge, not Fabric')
        ->and($versions->first()['id'])->toBe('v1');
});

// --- stage 2: switch the server to the pack's loader and version ---------

function mpEgg(string $name, array $vars): Pterodactyl\Models\Egg
{
    $egg = Pterodactyl\Models\Egg::query()->create(['name' => $name, 'docker_images' => ['Java 21' => 'ghcr.io/pterodactyl/yolks:java_21']]);
    foreach ($vars as $var => $default) {
        Pterodactyl\Models\EggVariable::query()->create(['egg_id' => $egg->id, 'env_variable' => $var, 'default_value' => $default]);
    }

    return $egg;
}

/** The pelican-eggs Fabric egg's variables, as on the panel. */
function mpFabricEgg(): Pterodactyl\Models\Egg
{
    return mpEgg('Fabric', ['SERVER_JARFILE' => 'server.jar', 'MC_VERSION' => 'latest', 'FABRIC_VERSION' => 'latest', 'LOADER_VERSION' => 'latest']);
}

function mpPanel(object $t): void
{
    panelTables();
    $t->server = Pterodactyl\Models\Server::query()->create(['uuid' => 'srv-mp', 'name' => 'MP']);
}

it('switches the server to a pack for another version when the user may', function () {
    mpPanel($this);
    $egg = mpFabricEgg();
    mpPublish($this, 'v121', ['mods/a.jar' => ['mod' => 'MODA']], ['overrides/config/a.toml' => 'x'], ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.5']);
    $this->wings->put('/world/level.dat', 'old world');
    $this->wings->put('/mods/old.jar', 'old');
    $this->wings->put('/eula.txt', 'eula=true');

    $p = mpSvc()->preview($this->server, 'PACK', 'v121', true);
    expect($p['blockers'])->toBe([])
        ->and($p['switch'])->toBe(['egg_name' => 'Fabric', 'from' => ['loader' => 'fabric', 'minecraft' => '1.20.1']])
        ->and($p['plan']['switch']['env'])->toBe(['LOADER_VERSION' => '0.16.5'])
        ->and(fn () => mpSvc()->start($this->server, 'PACK', 'v121', 1, true, false))->toThrow(ModpackException::class, 'deletes everything');

    $env = null;
    $this->ops->shouldReceive('changeEgg')->once()->andReturnUsing(function ($uuid, $eggId, $environment) use (&$env, $egg) {
        expect($eggId)->toBe($egg->id);
        $env = $environment;
    });
    $this->ops->shouldReceive('setDockerImage')->once()->with('srv-mp', 'ghcr.io/pterodactyl/yolks:java_21');
    $this->ops->shouldReceive('reinstall')->once()->andReturnUsing(function () {
        $this->installing = 2;   // "installing" for two polls
        $this->wings->put('/server.jar', 'fabric launcher');
    });

    mpSvc()->start($this->server, 'PACK', 'v121', 1, true, true);
    $pack = mpRun();

    $f = $this->wings->files;
    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED, (string) $pack->error)
        ->and([$env['MC_VERSION'], $env['LOADER_VERSION'], $env['FABRIC_VERSION']])->toBe(['1.21.1', '0.16.5', 'latest'])
        ->and($f)->not->toHaveKey('/world/level.dat')
        ->and($f)->not->toHaveKey('/mods/old.jar')
        ->and($f['/eula.txt'])->toBe('eula=true')
        ->and($f['/server.jar'])->toBe('fabric launcher')
        ->and($f['/mods/a.jar'])->toBe('jar:mods/a.jar:v121')
        ->and($f['/config/a.toml'])->toBe('x')
        ->and($pack->minecraft)->toBe('1.21.1')
        ->and($this->backupNames)->toHaveCount(1);
    $s = ModManagerSetting::forServer('srv-mp');
    expect([$s->loader, $s->game_version])->toBe(['fabric', '1.21.1']);
});

it('pins Forge as mc-loader and NeoForge as the loader version', function () {
    mpPanel($this);
    mpEgg('Forge Minecraft', ['SERVER_JARFILE' => 'server.jar', 'MC_VERSION' => 'latest', 'BUILD_TYPE' => 'recommended', 'FORGE_VERSION' => '']);
    mpEgg('NeoForge', ['MC_VERSION' => 'latest', 'NEOFORGE_VERSION' => '']);
    mpPublish($this, 'forge', ['mods/a.jar' => []], [], ['minecraft' => '1.20.1', 'forge' => '47.2.0']);
    mpPublish($this, 'neo', ['mods/a.jar' => []], [], ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);

    expect(mpSvc()->preview($this->server, 'PACK', 'forge', true)['plan']['switch']['env'])->toBe(['FORGE_VERSION' => '1.20.1-47.2.0'])
        ->and(mpSvc()->preview($this->server, 'PACK', 'neo', true)['plan']['switch']['env'])->toBe(['NEOFORGE_VERSION' => '21.1.77']);
});

it("won't switch without an egg for the loader, with backups off, or with install scripts skipped", function () {
    mpPanel($this);
    mpPublish($this, 'neo', ['mods/a.jar' => []], [], ['minecraft' => '1.21.1', 'neoforge' => '21.1.77']);
    expect(mpSvc()->preview($this->server, 'PACK', 'neo', true)['blockers'][0])->toContain('no Neoforge egg');

    mpFabricEgg();
    mpPublish($this, 'v121', ['mods/a.jar' => []], [], ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.5']);

    $this->server->forceFill(['skip_scripts' => true])->save();
    expect(mpSvc()->preview($this->server, 'PACK', 'v121', true)['blockers'][0])->toContain('Skip Egg Install Script');
    $this->server->forceFill(['skip_scripts' => false])->save();

    $ops = Mockery::mock(PanelServerOps::class);
    $ops->shouldReceive('serverBackupLimit')->andReturn(0);
    app()->instance(PanelServerOps::class, $ops);
    $p = mpSvc()->preview($this->server, 'PACK', 'v121', true);
    expect($p['switch'])->toBeNull()
        ->and($p['blockers'][0])->toContain('backups turned off');
});

it('fails the install, keeping the backup as the way back, when the reinstall fails', function () {
    mpPanel($this);
    mpFabricEgg();
    mpPublish($this, 'v121', ['mods/a.jar' => []], [], ['minecraft' => '1.21.1', 'fabric-loader' => '0.16.5']);
    $this->ops->shouldReceive('changeEgg', 'setDockerImage');
    $this->ops->shouldReceive('reinstall')->andReturnUsing(fn () => $this->server->forceFill(['status' => 'install_failed'])->save());
    $this->ops->shouldReceive('runtimeState')->andReturnUsing(fn () => ['power' => 'offline', 'install' => $this->server->refresh()->status]);

    mpSvc()->start($this->server, 'PACK', 'v121', 1, true, true);
    $pack = mpRun();

    expect($pack->status)->toBe(Modpack::STATUS_FAILED)
        ->and($pack->error)->toContain('reinstall onto Fabric failed')
        ->and($this->wings->files)->not->toHaveKey('/mods/a.jar');
});

// --- regressions from the test panel (2026-10-23) ------------------------

it("merges overrides into the existing mods and config folders, keeping the pack's jars", function () {
    // Vanilla Perfected ships overrides/mods/.panorama/... next to its jar downloads.
    mpPublish($this, 'v1', ['mods/a.jar' => ['mod' => 'MODA']], [
        'overrides/mods/.panorama/p1/panorama_name.txt' => 'p1',
        'overrides/config/x.json' => 'x',
    ]);
    $this->wings->put('/config/existing.toml', 'mine');

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    $pack = mpRun();

    $f = $this->wings->files;
    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED, (string) $pack->error)
        ->and($f['/mods/a.jar'])->toBe('jar:mods/a.jar:v1')
        ->and($f['/mods/.panorama/p1/panorama_name.txt'])->toBe('p1')
        ->and($f['/config/existing.toml'])->toBe('mine')
        ->and($f['/config/x.json'])->toBe('x');
});

it("installing the same version again puts back the pack's files that went missing", function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();
    unset($this->wings->files['/mods/a.jar']);

    $p = mpSvc()->preview($this->server, 'PACK', 'v1');
    expect(array_column($p['plan']['install'], 'path'))->toBe(['mods/a.jar'])
        ->and($p['counts']['unchanged'])->toBe(2);

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();
    expect($this->wings->files['/mods/a.jar'])->toBe('jar:mods/a.jar:v1');
});

it("waits out the panel's backup throttle instead of failing the install", function () {
    // Seen on the test panel: a loader switch's backup a minute earlier hit
    // the throttle, and the install failed at once.
    mpV1($this);
    $calls = 0;
    $this->rotation->shouldReceive('backupNow')->andReturnUsing(function (string $uuid, string $name) use (&$calls) {
        return ++$calls === 1
            ? RotationResult::skipped('throttled')
            : RotationResult::rotated(new BackupRef('b-1', $name, 0, false, false), [], 5);
    });

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    $pack = mpRun();

    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED, (string) $pack->error)
        ->and($calls)->toBe(2)
        ->and($pack->state['backup_attempts'])->toBe(1);
});

it('saves the file count as it goes, so the tab can show it', function () {
    mpV1($this);
    $seen = [];
    $wings = new class extends FakeWings {
        public ?\Closure $onPull = null;

        public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
        {
            ($this->onPull)?->__invoke();
            parent::pull($serverUuid, $url, $directory, $filename, $foreground);
        }
    };
    $wings->remote = $this->wings->remote;
    $wings->onPull = function () use (&$seen) {
        $p = Modpack::forServer('srv-mp');   // a fresh read, as the tab's poll does
        if ($p?->phase === 'files') {
            $seen[] = $p->progress_done;
        }
    };
    $this->wings = $wings;
    app()->instance(DaemonFileClient::class, $wings);

    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    expect($seen)->toBe([0, 1, 2]);
});

it('forgets the installed pack when the server is wiped, but not mid-install', function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    $pack = Modpack::forServer('srv-mp');
    $pack->forceFill(['status' => Modpack::STATUS_INSTALLING])->save();
    app(\ServerSuite\Services\ManifestService::class)->clearAll('srv-mp');
    expect(Modpack::forServer('srv-mp'))->not->toBeNull('a running install switches the server itself');

    $pack->forceFill(['status' => Modpack::STATUS_INSTALLED])->save();
    app(\ServerSuite\Services\ManifestService::class)->clearAll('srv-mp');
    expect(Modpack::forServer('srv-mp'))->toBeNull()
        ->and(ServerMod::forServer('srv-mp')->count())->toBe(0);
});

// --- removing a pack ------------------------------------------------------

it("removes a pack: its files and mods go, the owner's mods and world stay", function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();
    $this->wings->put('/mods/mine.jar', 'mine');
    $this->wings->put('/world/level.dat', 'world');
    ServerMod::query()->create(['server_uuid' => 'srv-mp', 'source' => 'modrinth', 'source_id' => 'MINE', 'mod_name' => 'Mine', 'installed_version' => '1', 'target_filename' => 'mine.jar', 'install_path' => '/mods']);
    $this->power = 'running';
    $this->backupNames = [];

    mpSvc()->remove($this->server, 1);
    expect(mpSvc()->status($this->server)['target']['action'])->toBe('remove');
    $pack = Modpack::forServer('srv-mp');
    for ($i = 0; $i < 20 && $pack->fresh()?->isRunning(); $i++) {
        (new InstallModpackJob($pack->id))->handle(app(ModpackInstaller::class));
    }

    $f = $this->wings->files;
    expect(Modpack::forServer('srv-mp'))->toBeNull()
        ->and(mpSvc()->status($this->server))->toBeNull()
        ->and($this->backupNames)->toBe(["Pre-remove \u{2192} Test Pack v1"])
        ->and($f)->not->toHaveKey('/mods/a.jar')
        ->and($f)->not->toHaveKey('/mods/b.jar')
        ->and($f)->not->toHaveKey('/config/c.json')
        ->and($f)->not->toHaveKey('/mods/extra.jar', "a jar from the pack's overrides is a mod too")
        ->and($f['/config/a.toml'])->toBe('server value', 'configs stay unless asked')
        ->and($f['/mods/mine.jar'])->toBe('mine')
        ->and($f['/world/level.dat'])->toBe('world')
        ->and(ServerMod::forServer('srv-mp')->pluck('source_id')->all())->toBe(['MINE']);
    $this->ops->shouldHaveReceived('power')->with('srv-mp', 'stop');
});

it('removes the pack\'s configs too when asked', function () {
    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();

    mpSvc()->remove($this->server, 1, true);
    $pack = Modpack::forServer('srv-mp');
    for ($i = 0; $i < 20 && $pack->fresh()?->isRunning(); $i++) {
        (new InstallModpackJob($pack->id))->handle(app(ModpackInstaller::class));
    }

    expect($this->wings->files)->not->toHaveKey('/config/a.toml');
});

it("won't remove a pack that isn't there or is still installing", function () {
    expect(fn () => mpSvc()->remove($this->server, 1))->toThrow(ModpackException::class, 'no modpack');

    mpV1($this);
    mpSvc()->start($this->server, 'PACK', 'v1', 1);
    mpRun();
    Modpack::forServer('srv-mp')->forceFill(['status' => Modpack::STATUS_INSTALLING])->save();
    expect(fn () => mpSvc()->remove($this->server, 1))->toThrow(ModpackException::class, 'running');
});
