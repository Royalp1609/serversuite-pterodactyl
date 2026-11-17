<?php

namespace ServerSuite\Support;

use Illuminate\Support\Facades\Http;

/**
 * The Java version a jar needs, read over HTTP without downloading it.
 *
 * A jar is a zip: the end of the file holds the central directory, and every
 * .class file starts with 0xCAFEBABE then its class-file major version
 * (52 = Java 8, 65 = Java 21, 69 = Java 25). This fetches the tail with a
 * Range request, finds a few .class entries, fetches just their first bytes
 * and returns the highest Java version among them (#174).
 *
 * Returns null whenever it can't tell (no Range support, ZIP64, encrypted or
 * odd entries, timeouts): callers then allow the install as before.
 */
class JarJava
{
    private const TAIL = 65557;            // largest EOCD record + comment
    private const MAX_CENTRAL_DIR = 2097152;
    private const CLASSES_TO_CHECK = 3;

    public static function javaFromMajor(int $major): ?int
    {
        return $major >= 45 ? max(1, $major - 44) : null;
    }

    public function required(string $url, int $timeout = 6): ?int
    {
        try {
            // Total size first: some CDNs (Modrinth's) answer a suffix range
            // ("bytes=-N") with 501 but serve explicit ranges.
            $probe = $this->range($url, 'bytes=0-0', $timeout);
            if ($probe === null) {
                return null;
            }
            $size = $probe[2];
            $from = max(0, $size - self::TAIL);
            $tail = $this->range($url, 'bytes=' . $from . '-' . ($size - 1), $timeout);
            if ($tail === null) {
                return null;
            }
            [$body, $start, $total] = $tail;

            $eocd = strrpos($body, "PK\x05\x06");
            if ($eocd === false || strlen($body) < $eocd + 22) {
                return null;
            }
            $e = unpack('vdisk/vcdDisk/vcountDisk/vcount/VcdSize/VcdOffset', substr($body, $eocd + 4, 16));
            if ($e['cdOffset'] === 0xFFFFFFFF || $e['cdSize'] === 0xFFFFFFFF || $e['cdSize'] > self::MAX_CENTRAL_DIR) {
                return null;   // ZIP64 or absurd
            }

            if ($e['cdOffset'] >= $start) {
                $cd = substr($body, $e['cdOffset'] - $start, $e['cdSize']);
            } else {
                $r = $this->range($url, 'bytes=' . $e['cdOffset'] . '-' . ($e['cdOffset'] + $e['cdSize'] - 1), $timeout);
                if ($r === null) {
                    return null;
                }
                $cd = $r[0];
            }

            $classes = $this->classEntries($cd);
            if ($classes === []) {
                return null;
            }
            $picked = array_values(array_unique([0, intdiv(count($classes), 2), count($classes) - 1]));
            $best = null;
            foreach (array_slice($picked, 0, self::CLASSES_TO_CHECK) as $i) {
                $java = $this->classJava($url, $classes[$i], $timeout);
                if ($java !== null) {
                    $best = max($best ?? 0, $java);
                }
            }

            return $best;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array{method: int, csize: int, offset: int}> */
    private function classEntries(string $cd): array
    {
        $out = [];
        $p = 0;
        $len = strlen($cd);
        while ($p + 46 <= $len && substr($cd, $p, 4) === "PK\x01\x02") {
            $h = unpack('vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr($cd, $p + 8, 38));
            $name = substr($cd, $p + 46, $h['nlen']);
            $encrypted = ($h['flags'] & 1) === 1;
            if (! $encrypted && in_array($h['method'], [0, 8], true)
                && str_ends_with($name, '.class') && ! str_starts_with($name, 'META-INF/')
                && ! str_ends_with($name, 'module-info.class') && ! str_ends_with($name, 'package-info.class')) {
                $out[] = ['method' => $h['method'], 'csize' => $h['csize'], 'offset' => $h['offset']];
            }
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
        }

        return $out;
    }

    /** @param array{method: int, csize: int, offset: int} $entry */
    private function classJava(string $url, array $entry, int $timeout): ?int
    {
        $want = min($entry['csize'], 4096);
        $r = $this->range($url, 'bytes=' . $entry['offset'] . '-' . ($entry['offset'] + 30 + 1024 + $want - 1), $timeout);
        if ($r === null || substr($r[0], 0, 4) !== "PK\x03\x04") {
            return null;
        }
        $h = unpack('vnlen/velen', substr($r[0], 26, 4));
        $data = substr($r[0], 30 + $h['nlen'] + $h['elen'], $want);

        if ($entry['method'] === 8) {
            $ctx = inflate_init(ZLIB_ENCODING_RAW);
            $data = $ctx === false ? '' : (string) @inflate_add($ctx, $data, ZLIB_SYNC_FLUSH);
        }
        if (strlen($data) < 8 || substr($data, 0, 4) !== "\xCA\xFE\xBA\xBE") {
            return null;
        }

        return self::javaFromMajor(unpack('n', substr($data, 6, 2))[1]);
    }

    /** @return array{0: string, 1: int, 2: int}|null body, first byte offset, total size */
    private function range(string $url, string $range, int $timeout): ?array
    {
        $res = Http::withHeaders(['Range' => $range, 'User-Agent' => 'ServerSuite (jar Java check)'])
            ->timeout($timeout)->connectTimeout(3)->get($url);
        if ($res->status() !== 206 || ! preg_match('#bytes (\d+)-(\d+)/(\d+)#', (string) $res->header('Content-Range'), $m)) {
            return null;
        }

        return [$res->body(), (int) $m[1], (int) $m[3]];
    }
}
