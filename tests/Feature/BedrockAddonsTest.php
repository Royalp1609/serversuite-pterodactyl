<?php

use ServerSuite\Services\BedrockAddonService;
use ServerSuite\Support\BedrockArchive;
use ServerSuite\Tests\Fakes\FakeWings;

/**
 * #93 (Pelican #97): Bedrock add-ons. Archives are built here as real zips,
 * laid out as Bedrock exports them; FakeWings really unpacks them.
 */

const ADDON_BP = 'a1b2c3d4-0000-4000-8000-00000000000b';
const ADDON_RP = 'a1b2c3d4-0000-4000-8000-00000000000e';
const ADDON_WORLD = '/worlds/Bedrock level';

/** @param array<string, string> $entries name => bytes; a name ending in "@" is stored as a symlink */
function addonZip(array $entries): string
{
    $file = tempnam(sys_get_temp_dir(), 'ss-test-zip');
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $bytes) {
        $link = str_ends_with($name, '@');
        $name = $link ? substr($name, 0, -1) : $name;
        $zip->addFromString($name, $bytes);
        if ($link) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0120777 << 16);
        }
    }
    $zip->close();
    $b = (string) file_get_contents($file);
    unlink($file);

    return $b;
}

function addonBehaviorManifest(array $version = [1, 0, 0], bool $beta = true): string
{
    return "// exported by bridge.\n" . json_encode([
        'format_version' => 2,
        'header' => ['name' => 'pack.name', 'description' => 'pack.description', 'uuid' => ADDON_BP, 'version' => $version],
        'modules' => [['type' => 'data', 'uuid' => 'b1b2c3d4-0000-4000-8000-000000000001', 'version' => $version],
            ['type' => 'script', 'language' => 'javascript', 'uuid' => 'b1b2c3d4-0000-4000-8000-000000000002', 'version' => $version, 'entry' => 'scripts/main.js']],
        'dependencies' => [['uuid' => ADDON_RP, 'version' => $version], ['module_name' => '@minecraft/server', 'version' => $beta ? '1.17.0-beta' : '1.16.0']],
    ], JSON_PRETTY_PRINT);
}

function addonResourceManifest(array $version = [1, 0, 0]): string
{
    return json_encode([
        'format_version' => 2,
        'header' => ['name' => '§aMore Mobs Textures', 'description' => 'Textures', 'uuid' => ADDON_RP, 'version' => $version],
        'modules' => [['type' => 'resources', 'uuid' => 'c1b2c3d4-0000-4000-8000-000000000001', 'version' => $version]],
    ]);
}

function addonArchive(array $version = [1, 0, 0]): string
{
    return addonZip([
        'More Mobs BP/manifest.json' => addonBehaviorManifest($version),
        'More Mobs BP/texts/en_US.lang' => "pack.name=More Mobs\npack.description=Adds mobs ## comment\n",
        'More Mobs BP/entities/mob.json' => '{}',
        'More Mobs RP/manifest.json' => addonResourceManifest($version),
        'More Mobs RP/textures/mob.png' => "\x89PNG",
    ]);
}

function addonPacksOf(string $bytes, int $max = 536870912): array
{
    $file = tempnam(sys_get_temp_dir(), 'ss');
    file_put_contents($file, $bytes);
    try {
        return BedrockArchive::packs($file, $max);
    } finally {
        unlink($file);
    }
}

beforeEach(function () {
    $this->wings = new FakeWings();
    $this->svc = new BedrockAddonService($this->wings);
    $this->wings->put('/server.properties', "server-name=Dedicated Server\nlevel-name=Bedrock level\ntexturepack-required=false\n");
    $this->wings->makeDirectory('s', ADDON_WORLD);
    $this->wings->put('/behavior_packs/vanilla/manifest.json', json_encode(['header' => ['uuid' => 'fe9f8597-5454-481a-8730-8d070a8e2e58', 'version' => [1, 21, 0]], 'modules' => [['type' => 'data']]]));
    $this->list = fn (string $type) => json_decode((string) $this->wings->readFile('s', ADDON_WORLD . '/' . BedrockAddonService::WORLD_FILES[$type]), true) ?? [];
    $this->under = fn (string $prefix) => array_values(array_filter(array_keys($this->wings->files), fn ($p) => str_starts_with($p, $prefix)));
});

it('reads an .mcaddon: names, types and the Beta APIs need', function () {
    $packs = addonPacksOf(addonArchive());

    expect(array_column($packs, 'type'))->toBe(['behavior', 'resource'])
        ->and(array_column($packs, 'name'))->toBe(['More Mobs', 'More Mobs Textures'])
        ->and($packs[0]['description'])->toBe('Adds mobs')
        ->and($packs[0]['needs_beta'])->toBeTrue()
        ->and($packs[1]['needs_beta'])->toBeFalse()
        ->and($packs[0]['dependencies'])->toBe([['uuid' => ADDON_RP, 'version' => [1, 0, 0]]]);
});

it('refuses unsafe or wrong archives', function (array $entries, string $message) {
    expect(fn () => addonPacksOf(addonZip($entries)))->toThrow(RuntimeException::class, $message);
})->with([
    'zip slip' => [['pack/manifest.json' => '{"header":{"uuid":"' . ADDON_RP . '","version":[1,0,0]},"modules":[{"type":"resources"}]}', 'pack/../../server.properties' => 'x'], 'unsafe file path'],
    'absolute' => [['/etc/manifest.json' => '{}'], 'unsafe file path'],
    'backslash' => [['pack\\..\\x/manifest.json' => '{}'], 'unsafe file path'],
    'symlink' => [['manifest.json' => '{"header":{"uuid":"' . ADDON_RP . '","version":[1,0,0]},"modules":[{"type":"resources"}]}', 'link@' => '/etc/passwd'], 'symlink'],
    'no manifest' => [['textures/a.png' => 'x'], 'No pack found'],
    'skin pack' => [['manifest.json' => '{"header":{"uuid":"' . ADDON_RP . '","version":[1,0,0]},"modules":[{"type":"skin_pack"}]}'], 'skin pack'],
    'world template' => [['manifest.json' => '{"header":{"uuid":"' . ADDON_RP . '","version":[1,0,0]},"modules":[{"type":"world_template"}]}'], 'world template'],
    'mixed modules' => [['manifest.json' => '{"header":{"uuid":"' . ADDON_RP . '","version":[1,0,0]},"modules":[{"type":"data"},{"type":"resources"}]}'], 'either a resources module'],
]);

it('refuses a zip bomb by its unpacked size, and a file that is not a zip', function () {
    expect(fn () => addonPacksOf(addonZip(['manifest.json' => addonResourceManifest(), 'big.bin' => str_repeat("\0", 2 * 1048576)]), 1048576))
        ->toThrow(RuntimeException::class, 'unpacks to more than 1 MB')
        ->and(fn () => addonPacksOf('not a zip'))->toThrow(RuntimeException::class, 'not a valid .mcpack');
});

it('installs both packs, switches them on and empties the inbox', function () {
    $this->wings->put('/addons/More Mobs.mcaddon', addonArchive());

    $r = $this->svc->install('s', 'More Mobs.mcaddon');

    expect($r['installed'])->toBe(['More Mobs 1.0.0', 'More Mobs Textures 1.0.0'])
        ->and(implode(' ', $r['notes']))->toContain('Beta APIs')
        ->and($this->wings->readFile('s', '/behavior_packs/More_Mobs_a1b2c3d4/entities/mob.json'))->toBe('{}')
        ->and($this->wings->readFile('s', '/resource_packs/More_Mobs_Textures_a1b2c3d4/textures/mob.png'))->toBe("\x89PNG")
        ->and(($this->list)('behavior'))->toBe([['pack_id' => ADDON_BP, 'version' => [1, 0, 0]]])
        ->and(($this->list)('resource'))->toBe([['pack_id' => ADDON_RP, 'version' => [1, 0, 0]]])
        ->and(($this->under)('/addons/'))->toBe([]);

    $state = $this->svc->installed('s');
    expect(array_column($state['packs']['behavior'], 'name'))->toBe(['More Mobs'])   // not BDS's own vanilla pack
        ->and($state['packs']['behavior'][0]['missing'])->toBe([]);
});

it('refuses the same version and gives the file back', function () {
    $this->wings->put('/addons/a.mcaddon', addonArchive());
    $this->svc->install('s', 'a.mcaddon');
    $this->wings->put('/addons/a.mcaddon', $bytes = addonArchive());

    expect(fn () => $this->svc->install('s', 'a.mcaddon'))->toThrow(RuntimeException::class, 'Already installed at this version')
        ->and($this->wings->readFile('s', '/addons/a.mcaddon', 10485760))->toBe($bytes)
        ->and(($this->under)('/addons/'))->toBe(['/addons/a.mcaddon']);
});

it('replaces an older version in place, keeping its priority', function () {
    $this->wings->put('/addons/a.mcaddon', addonArchive());
    $this->svc->install('s', 'a.mcaddon');
    $this->wings->put('/behavior_packs/More_Mobs_a1b2c3d4/old-only.json', '{}');
    $other = '99999999-0000-4000-8000-000000000000';
    $this->wings->put(ADDON_WORLD . '/world_behavior_packs.json', json_encode([['pack_id' => $other, 'version' => [2, 0, 0]], ['pack_id' => ADDON_BP, 'version' => [1, 0, 0]]]));
    $this->wings->put('/addons/a2.mcaddon', addonArchive([1, 1, 0]));

    expect($this->svc->install('s', 'a2.mcaddon')['updated'])->toBe(['More Mobs 1.1.0', 'More Mobs Textures 1.1.0'])
        ->and($this->wings->readFile('s', '/behavior_packs/More_Mobs_a1b2c3d4/old-only.json'))->toBeNull()
        ->and(($this->list)('behavior'))->toBe([['pack_id' => $other, 'version' => [2, 0, 0]], ['pack_id' => ADDON_BP, 'version' => [1, 1, 0]]]);

    // An update of a pack that is switched off leaves it off.
    $this->svc->setEnabled('s', 'resource', ADDON_RP, false);
    $this->wings->put('/addons/a3.mcaddon', addonArchive([1, 2, 0]));
    $this->svc->install('s', 'a3.mcaddon');
    expect(($this->list)('resource'))->toBe([]);
});

it('installs nested .mcpacks and a pack at the archive root', function () {
    $this->wings->put('/addons/nested.mcaddon', addonZip(['RP.mcpack' => addonZip(['manifest.json' => addonResourceManifest(), 'textures/a.png' => 'png'])]));
    expect($this->svc->install('s', 'nested.mcaddon')['installed'])->toBe(['More Mobs Textures 1.0.0'])
        ->and($this->wings->readFile('s', '/resource_packs/More_Mobs_Textures_a1b2c3d4/textures/a.png'))->toBe('png');

    $this->wings->put('/addons/bp.mcpack', addonZip(['manifest.json' => addonBehaviorManifest([1, 0, 0], false), 'entities/a.json' => '{}']));
    expect($this->svc->install('s', 'bp.mcpack')['notes'])->toBe([])
        ->and($this->wings->readFile('s', '/behavior_packs/pack_name_a1b2c3d4/entities/a.json'))->toBe('{}')
        ->and(($this->under)('/addons/'))->toBe([]);
});

it('leaves nothing behind but the file when an archive is unsafe', function () {
    $this->wings->put('/addons/evil.mcpack', $bytes = addonZip(['manifest.json' => addonResourceManifest(), '../server.properties' => 'level-name=gone']));

    expect(fn () => $this->svc->install('s', 'evil.mcpack'))->toThrow(RuntimeException::class, 'unsafe file path')
        ->and($this->wings->readFile('s', '/addons/evil.mcpack', 10485760))->toBe($bytes)
        ->and((string) $this->wings->readFile('s', '/server.properties'))->toContain('level-name=Bedrock level')
        ->and(($this->under)('/addons/'))->toBe(['/addons/evil.mcpack'])
        ->and(($this->under)('/resource_packs/'))->toBe([]);
});

it('installs only files in the inbox', function () {
    $this->wings->put('/behavior_packs/x.mcpack', addonZip(['manifest.json' => addonResourceManifest()]));
    foreach (['../behavior_packs/x.mcpack', 'missing.mcpack', 'notes.txt', '.serversuite-tmp-1/archive.zip'] as $bad) {
        expect(fn () => $this->svc->install('s', $bad))->toThrow(RuntimeException::class);
    }
});

it('switches, orders and removes, and leaves foreign entries alone', function () {
    $this->wings->put('/addons/a.mcaddon', addonArchive());
    $this->svc->install('s', 'a.mcaddon');
    $other = '99999999-0000-4000-8000-000000000000';
    $odd = "x'); alert(1); ('";
    $this->wings->put(ADDON_WORLD . '/world_behavior_packs.json', json_encode([['pack_id' => ADDON_BP, 'version' => [1, 0, 0]], ['pack_id' => $other, 'version' => [2, 0, 0]], ['pack_id' => $odd, 'version' => [1, 0, 0]]]));

    $rows = $this->svc->installed('s')['packs']['behavior'];
    expect(array_column($rows, 'uuid'))->toBe([ADDON_BP, $other])   // a non-UUID entry isn't shown
        ->and($rows[1]['ours'])->toBeFalse();

    $this->svc->move('s', 'behavior', $other, -1);
    expect(array_column(($this->list)('behavior'), 'pack_id'))->toBe([$other, ADDON_BP, $odd])
        ->and($this->svc->setEnabled('s', 'behavior', $other, false))->toContain('Switched off')
        ->and(fn () => $this->svc->remove('s', 'behavior', $other))->toThrow(RuntimeException::class, 'Only packs installed here can be removed here');

    $this->svc->setEnabled('s', 'behavior', ADDON_BP, false);
    expect($this->svc->setEnabled('s', 'behavior', ADDON_BP, true))->toContain('Beta APIs')
        ->and(($this->list)('behavior')[0]['pack_id'])->toBe(ADDON_BP);
    $this->svc->remove('s', 'resource', ADDON_RP);
    expect(($this->list)('resource'))->toBe([])
        ->and(($this->under)('/resource_packs/'))->toBe([]);
});

it('sets texturepack-required, and waits for the world before switching packs on', function () {
    $this->svc->setTexturepackRequired('s', true);
    expect((string) $this->wings->readFile('s', '/server.properties'))->toContain("texturepack-required=true\n")
        ->and($this->svc->installed('s')['texturepack_required'])->toBeTrue();

    $this->wings->put('/server.properties', "level-name=New world\n");
    $this->wings->put('/addons/a.mcaddon', addonArchive());
    expect(implode(' ', $this->svc->install('s', 'a.mcaddon')['notes']))->toContain('Start the server once')
        ->and($this->wings->readFile('s', '/worlds/New world/world_behavior_packs.json'))->toBeNull()
        ->and(fn () => $this->svc->setEnabled('s', 'behavior', ADDON_BP, true))->toThrow(RuntimeException::class, 'doesn\'t exist yet');
});

it('knows Bedrock Dedicated Server from PocketMine, Nukkit and Java', function () {
    $egg = fn (string $name) => (object) ['name' => $name];
    expect(BedrockAddonService::isBedrock((object) ['startup' => './bedrock_server', 'egg' => $egg('Vanilla Bedrock')]))->toBeTrue()
        ->and(BedrockAddonService::isBedrock((object) ['startup' => './start.sh', 'egg' => $egg('Bedrock (custom)')]))->toBeTrue()
        ->and(BedrockAddonService::isBedrock((object) ['startup' => './start.sh', 'egg' => $egg('PocketMine-MP Bedrock')]))->toBeFalse()
        ->and(BedrockAddonService::isBedrock((object) ['startup' => 'java -jar nukkit.jar', 'egg' => $egg('Nukkit Bedrock')]))->toBeFalse()
        ->and(BedrockAddonService::isBedrock((object) ['startup' => 'java -jar server.jar', 'egg' => $egg('Paper')]))->toBeFalse();
});
