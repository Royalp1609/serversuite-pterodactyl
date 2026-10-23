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
