<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Services\JavaCompatService;
use ServerSuite\Support\Data\ModVersion;
use ServerSuite\Support\JarJava;

/** A real jar with classes compiled for $major (stored or deflated), served with Range support. */
function fakeJar(int $major, bool $deflate = true, int $libMajor = 52): string
{
    $path = tempnam(sys_get_temp_dir(), 'jar');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $class = fn (int $m) => "\xCA\xFE\xBA\xBE\x00\x00" . pack('n', $m) . str_repeat("\x01", 200);
    $zip->addFromString('META-INF/MANIFEST.MF', "Manifest-Version: 1.0\n");
    $zip->addFromString('plugin.yml', "name: Demo\nmain: demo.Main\n");
    $zip->addFromString('demo/Main.class', $class($major));
    $zip->addFromString('demo/lib/Old.class', $class($libMajor));
    $zip->addFromString('demo/Zzz.class', $class($major));
    if (! $deflate) {
        foreach (['demo/Main.class', 'demo/lib/Old.class', 'demo/Zzz.class'] as $n) {
            $zip->setCompressionName($n, ZipArchive::CM_STORE);
        }
    }
    $zip->close();
    $bytes = file_get_contents($path);
    unlink($path);

    return $bytes;
}

function serveWithRanges(string $bytes, bool $ranges = true, bool $suffix = false): void
{
    Http::fake(function (Request $req) use ($bytes, $ranges) {
        $total = strlen($bytes);
        $range = $req->header('Range')[0] ?? '';
        if (! $ranges || ! preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            return Http::response($bytes, 200);
        }
        if ($m[1] === '' && ! $suffix) {
            return Http::response('Not Implemented', 501);   // like Modrinth's CDN
        }
        if ($m[1] === '') {
            $start = max(0, $total - (int) $m[2]);
            $end = $total - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $total - 1 : min($total - 1, (int) $m[2]);
        }

        return Http::response(substr($bytes, $start, $end - $start + 1), 206, ['Content-Range' => "bytes {$start}-{$end}/{$total}"]);
    });
}

function jarVersion(string $url = 'https://cdn.example.com/demo-1.0.jar'): ModVersion
{
    return new ModVersion('v1', '1.0', ['paper'], ['1.21.10'], $url, 'demo-1.0.jar', 1000, null);
}

beforeEach(fn () => Cache::flush());

it('reads the server Java from common image tags', function (string $image, ?int $java) {
    expect(JavaCompatService::serverJava((object) ['image' => $image]))->toBe($java);
})->with([
    ['ghcr.io/pterodactyl/yolks:java_21', 21],
    ['ghcr.io/pelican-eggs/yolks:java_25', 25],
    ['ghcr.io/parkervcp/yolks:java_8', 8],
    ['eclipse-temurin:17-jre', 17],
    ['example/minecraft:java17', 17],
    ['ghcr.io/pterodactyl/yolks:debian', null],
    ['', null],
]);

it('reads the Java a jar needs from deflated classes', function () {
    serveWithRanges(fakeJar(69));
    expect((new JarJava())->required('https://cdn.example.com/a.jar'))->toBe(25);
});

it('reads the Java a jar needs from stored classes', function () {
    serveWithRanges(fakeJar(65, deflate: false));
    expect((new JarJava())->required('https://cdn.example.com/b.jar'))->toBe(21);
});

it('takes the highest class version in the jar', function () {
    serveWithRanges(fakeJar(61, libMajor: 52));
    expect((new JarJava())->required('https://cdn.example.com/c.jar'))->toBe(17);
});

it('gives up (unknown) when the host ignores Range requests', function () {
    serveWithRanges(fakeJar(69), ranges: false);
    expect((new JarJava())->required('https://cdn.example.com/d.jar'))->toBeNull();
});

it('refuses a build that needs a newer Java than the server runs, and allows the rest', function () {
    serveWithRanges(fakeJar(69));
    $svc = new JavaCompatService(new JarJava());

    expect(fn () => $svc->assertRuns((object) ['image' => 'ghcr.io/pterodactyl/yolks:java_21'], jarVersion(), 'WorldEdit'))
        ->toThrow(DeliveryException::class, 'WorldEdit 1.0 needs Java 25, and this server runs Java 21');

    $svc->assertRuns((object) ['image' => 'ghcr.io/pterodactyl/yolks:java_25'], jarVersion(), 'WorldEdit');
    $svc->assertRuns((object) ['image' => 'custom/image:latest'], jarVersion(), 'WorldEdit');
    expect(true)->toBeTrue();
});

it('can be turned off', function () {
    serveWithRanges(fakeJar(69));
    config(['serversuite.java_check' => false]);
    (new JavaCompatService(new JarJava()))->assertRuns((object) ['image' => 'ghcr.io/pterodactyl/yolks:java_17'], jarVersion(), 'X');
    expect(true)->toBeTrue();
});
