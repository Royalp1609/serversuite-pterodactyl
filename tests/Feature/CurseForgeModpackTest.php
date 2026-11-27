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
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Support\Data\RotationResult;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #23 stage 3: CurseForge modpacks (manifest.json, files through the API),
 * against a faked CurseForge API and a fake Wings. Ported from the Pelican
 * plugin's CurseForgeModpackTest (pelican-server-suite#16).
 */

const CF_CDN = 'https://edge.forgecdn.net/files';

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    config(['serversuite.curseforge_api_key' => 'test-key']);

    $this->server = (object) ['uuid' => 'srv-cf'];
    ModManagerSetting::forServer('srv-cf')->forceFill(['loader' => 'forge', 'game_version' => '1.20.1'])->save();
    $this->wings = new FakeWings();
    app()->instance(DaemonFileClient::class, $this->wings);

    $ops = Mockery::mock(PanelServerOps::class);
    $ops->shouldReceive('runtimeState')->andReturn(['power' => 'offline', 'install' => null]);
    $ops->shouldReceive('serverBackupLimit')->andReturn(5);
    $ops->shouldReceive('listBackups')->andReturn([new BackupRef('b-1', 'Pre-modpack', 10, false, true, now()->toIso8601String(), now()->toIso8601String())]);
    app()->instance(PanelServerOps::class, $ops);
    $rotation = Mockery::mock(BackupRotationService::class);
    $rotation->shouldReceive('backupNow')->andReturn(RotationResult::rotated(new BackupRef('b-1', 'Pre-modpack', 0, false, false), [], 5));
    app()->instance(BackupRotationService::class, $rotation);

    $this->packZip = cfZip([
        'manifest.json' => json_encode([
            'manifestType' => 'minecraftModpack', 'manifestVersion' => 1, 'name' => 'CF Pack', 'version' => '1.0',
            'minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'forge-47.2.0', 'primary' => true]]],
            'files' => [
                ['projectID' => 2001, 'fileID' => 7001, 'required' => true],
                ['projectID' => 2002, 'fileID' => 7002, 'required' => true],
                ['projectID' => 2003, 'fileID' => 7003, 'required' => true],
                ['projectID' => 2004, 'fileID' => 7004, 'required' => false],
            ],
            'overrides' => 'overrides',
        ]),
        'overrides/config/create-common.toml' => 'x = 1',
    ]);
    $this->wings->remote[CF_CDN . '/5001/1/cfpack-1.0.zip'] = $this->packZip;
    $this->wings->remote[CF_CDN . '/7001/1/create-1.20.1-0.5.1.jar'] = 'create jar';

    $file = fn (int $id, int $mod, string $name, ?string $url, string $bytes) => [
        'id' => $id, 'modId' => $mod, 'displayName' => $name, 'fileName' => $name, 'downloadUrl' => $url,
        'fileLength' => strlen($bytes), 'hashes' => [['value' => sha1($bytes), 'algo' => 1]], 'gameVersions' => ['1.20.1', 'Forge'],
    ];
    Http::fake(function (Request $r) use ($file) {
        $path = parse_url($r->url(), PHP_URL_PATH);
        if (parse_url($r->url(), PHP_URL_HOST) === 'edge.forgecdn.net') {
            return Http::response($this->wings->remote[$r->url()] ?? '', isset($this->wings->remote[$r->url()]) ? 200 : 404);
        }
        expect($r->header('x-api-key')[0] ?? null)->toBe('test-key', 'API key sent to the CurseForge API');

        return match (true) {
            $path === '/v1/mods/search' => Http::response(['data' => [cfProject(1000, 'CF Pack')]]),
            $path === '/v1/mods/1000' => Http::response(['data' => cfProject(1000, 'CF Pack')]),
            $path === '/v1/mods/1000/files' => Http::response(['data' => [
                $file(5001, 1000, 'cfpack-1.0.zip', CF_CDN . '/5001/1/cfpack-1.0.zip', $this->packZip) + ['fileDate' => '2026-10-01', 'serverPackFileId' => 5002],
                $file(5002, 1000, 'cfpack-1.0-server.zip', CF_CDN . '/5002/1/server.zip', 'srv') + ['fileDate' => '2026-10-01', 'isServerPack' => true],
            ]]),
            $path === '/v1/mods/1000/files/5001' => Http::response(['data' => $file(5001, 1000, 'cfpack-1.0.zip', CF_CDN . '/5001/1/cfpack-1.0.zip', $this->packZip)]),
            $path === '/v1/mods/files' => Http::response(['data' => [
                $file(7001, 2001, 'create-1.20.1-0.5.1.jar', CF_CDN . '/7001/1/create-1.20.1-0.5.1.jar', 'create jar'),
                $file(7002, 2002, 'optifine.jar', null, 'opti'),
                $file(7003, 2003, 'faithful.zip', CF_CDN . '/7003/1/faithful.zip', 'rp'),
                $file(7004, 2004, 'extra.jar', CF_CDN . '/7004/1/extra.jar', 'extra'),
            ]]),
            $path === '/v1/mods' && $r->method() === 'POST' => Http::response(['data' => [cfProject(2001, 'Create')]]),
            default => Http::response(['error' => "not faked: {$r->method()} {$path}"], 404),
        };
    });
});

/** @param array<string, string> $entries */
function cfZip(array $entries): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cfpack');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $zip->addFromString($name, $bytes);
    }
    $zip->close();
    $bytes = file_get_contents($tmp);
    unlink($tmp);

    return $bytes;
}

function cfProject(int $id, string $name): array
{
    return ['id' => $id, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)), 'summary' => 'x', 'authors' => [['name' => 'a']],
        'logo' => null, 'links' => ['websiteUrl' => "https://www.curseforge.com/minecraft/modpacks/{$id}"], 'downloadCount' => 5];
}

it('offers CurseForge only with an API key', function () {
    expect(app(ModpackService::class)->sources())->toBe(['modrinth' => 'Modrinth', 'curseforge' => 'CurseForge']);

    config(['serversuite.curseforge_api_key' => '']);
    expect(app(ModpackService::class)->sources())->toBe(['modrinth' => 'Modrinth'])
        ->and(fn () => app(ModpackService::class)->driver('curseforge'))->toThrow(ModpackException::class, 'CurseForge needs an API key');
});

it('leaves server packs out of search and versions', function () {
    expect(array_column(app(ModpackService::class)->search($this->server, 'cf', source: 'curseforge'), 'name'))->toBe(['CF Pack'])
        ->and(array_column(app(ModpackService::class)->versions('1000', $this->server, 'curseforge'), 'id'))->toBe(['5001']);
});

it("reports the files CurseForge won't let us download", function () {
    $p = app(ModpackService::class)->preview($this->server, '1000', '5001', false, 'curseforge');
    $reasons = array_column($p['skipped'], 'reason', 'path');

    expect($p['blockers'])->toBe([])
        ->and($p['requires'])->toBe(['loader' => 'forge', 'loader_version' => '47.2.0', 'minecraft' => '1.20.1'])
        ->and($p['counts']['install'])->toBe(1)
        ->and($reasons['mods/optifine.jar'])->toContain('does not allow downloads by other apps')
        ->and($reasons['mods/faithful.zip'])->toContain('resource or shader pack')
        ->and($reasons['mods/extra.jar'])->toBe('optional in the pack')
        ->and($p['plan']['install'][0]['mod']['name'])->toBe('Create')
        ->and([$p['plan']['index_file'], $p['plan']['override_dirs']])->toBe(['manifest.json', ['overrides']]);
});

it('installs a CurseForge pack end to end', function () {
    app(ModpackService::class)->start($this->server, '1000', '5001', 1, false, false, 'curseforge');
    $pack = Modpack::forServer('srv-cf');
    for ($i = 0; $i < 30 && $pack->refresh()->isRunning(); $i++) {
        (new InstallModpackJob($pack->id))->handle(app(ModpackInstaller::class));
    }
    $mod = ServerMod::forServer('srv-cf')->sole();

    expect($pack->status)->toBe(Modpack::STATUS_INSTALLED, (string) $pack->error)
        ->and($pack->source)->toBe('curseforge')
        ->and(app(ModpackService::class)->status($this->server)['installed']['source'])->toBe('curseforge')
        ->and(app(ModpackService::class)->status($this->server)['installed']['version_id'])->toBe('5001')
        ->and($this->wings->files['/mods/create-1.20.1-0.5.1.jar'])->toBe('create jar')
        ->and($this->wings->files['/config/create-common.toml'])->toBe('x = 1')
        ->and($this->wings->files)->not->toHaveKey('/mods/optifine.jar')
        ->and([$mod->source, $mod->source_id, $mod->mod_name, $mod->modpack_id])->toBe(['curseforge', '2001', 'Create', $pack->id]);
});

it("only allows downloads from CurseForge's CDN, and refuses unsafe overrides folders", function () {
    $manifest = json_encode(['manifestType' => 'minecraftModpack', 'minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'neoforge-1.20.1-47.1.84', 'primary' => true]]],
        'files' => [['projectID' => 1, 'fileID' => 1, 'required' => true]]]);
    $index = MrpackIndex::fromCurseForge($manifest, ['1' => ['id' => 1, 'fileName' => 'x.jar', 'downloadUrl' => 'https://evil.example.com/x.jar', 'hashes' => [['value' => str_repeat('a', 40), 'algo' => 1]]]]);

    expect([$index->loader, $index->loaderVersion])->toBe(['neoforge', '47.1.84'])
        ->and($index->files[0]['action'])->toBe('blocked')
        ->and(fn () => MrpackIndex::fromCurseForge(json_encode(['manifestType' => 'minecraftModpack', 'minecraft' => ['version' => '1.20.1'], 'overrides' => '../../etc']), []))
        ->toThrow(ModpackException::class);
});
