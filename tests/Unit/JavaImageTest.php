<?php

use ServerSuite\Services\LoaderSwitchService;
use ServerSuite\Services\ServerVersionService;

/**
 * The Java image for a Minecraft version is the one Mojang ships for it
 * (the lowest Java the version allows), not the newest. Seen on the test
 * panel (2026-10-23): a modpack's switch from 26.3 to 1.21.1 kept Java 25,
 * and Cobblemon refused to load ("requires version 21 ... present: 25").
 */

const YOLKS = [
    'Java 8'  => 'ghcr.io/pterodactyl/yolks:java_8',
    'Java 17' => 'ghcr.io/pterodactyl/yolks:java_17',
    'Java 21' => 'ghcr.io/pterodactyl/yolks:java_21',
    'Java 25' => 'ghcr.io/pterodactyl/yolks:java_25',
];

it('switches a version change to the Java that version ships with', function () {
    $svc = app(ServerVersionService::class);

    $down = $svc->resolveImage(YOLKS, YOLKS['Java 25'], $svc->requiredJavaBand('1.21.1'));
    expect([$down['resolved'], $down['change']])->toBe([YOLKS['Java 21'], true]);

    $same = $svc->resolveImage(YOLKS, YOLKS['Java 21'], $svc->requiredJavaBand('1.21.4'));
    expect([$same['resolved'], $same['change']])->toBe([YOLKS['Java 21'], false]);

    expect($svc->resolveImage(YOLKS, YOLKS['Java 21'], $svc->requiredJavaBand('26.3'))['resolved'])->toBe(YOLKS['Java 25'])
        ->and($svc->resolveImage(YOLKS, null, $svc->requiredJavaBand('1.20.1'))['resolved'])->toBe(YOLKS['Java 17']);
});

it('picks the same Java for a loader switch', function () {
    $egg = (object) ['docker_images' => YOLKS];
    $svc = app(LoaderSwitchService::class);

    expect($svc->resolveEggImage($egg, '1.21.1'))->toBe(YOLKS['Java 21'])
        ->and($svc->resolveEggImage($egg, '26.3'))->toBe(YOLKS['Java 25'])
        ->and($svc->resolveEggImage($egg, '1.16.5'))->toBe(YOLKS['Java 8']);
});

it("keeps a newer Java when installed plugins need it", function () {
    $svc = app(ServerVersionService::class);
    $band = $svc->requiredJavaBand('1.21.8');

    // VeinMiner Enchantment needs Java 25: the switch keeps Java 25 instead of dropping to 21.
    $keep = $svc->resolveImage(YOLKS, YOLKS['Java 25'], $band, 25);
    expect([$keep['resolved'], $keep['change'], $keep['mods_need']])->toBe([YOLKS['Java 25'], false, 25])
        ->and($keep['note'])->toContain('installed plugins need Java 25');

    // From Java 21 it moves up to the lowest Java that covers them.
    expect($svc->resolveImage(YOLKS, YOLKS['Java 21'], $band, 22)['resolved'])->toBe(YOLKS['Java 25']);

    // Plugins that run on the version's own Java change nothing.
    expect($svc->resolveImage(YOLKS, YOLKS['Java 25'], $band, 17)['resolved'])->toBe(YOLKS['Java 21'])
        ->and($svc->resolveImage(YOLKS, YOLKS['Java 25'], $band, null)['resolved'])->toBe(YOLKS['Java 21']);
});

it("says so when the version's images can't run the installed plugins", function () {
    $svc = app(ServerVersionService::class);
    // 1.16.5 allows Java 8-11; a plugin built for 17 can't run on any of them.
    $r = $svc->resolveImage(YOLKS, YOLKS['Java 17'], $svc->requiredJavaBand('1.16.5'), 17);

    expect($r['resolved'])->toBe(YOLKS['Java 8'])
        ->and($r['allowed'])->toBeTrue()
        ->and($r['note'])->toContain("won't load");
});
