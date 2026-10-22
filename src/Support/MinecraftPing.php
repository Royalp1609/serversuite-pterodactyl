<?php

namespace ServerSuite\Support;

/**
 * Minecraft Server List Ping (Java 1.7+): the same request the multiplayer
 * screen sends. The hostname goes in the handshake, so a server behind a
 * hostname router (mc-router, Velocity forced hosts) answers for that name.
 */
class MinecraftPing
{
    /** Status JSON bigger than this is refused (modded servers list their mods). */
    private const MAX_BYTES = 262144;

    /**
     * @return array<string, mixed> the server's status JSON, decoded
     *
     * @throws \RuntimeException when the server can't be reached or answers badly
     */
    public function ping(string $host, int $port, string $hostname, float $timeout = 2.0): array
    {
        $errno = 0;
        $errstr = '';
        $addr = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $sock = @stream_socket_client("tcp://{$addr}:{$port}", $errno, $errstr, $timeout);
        if ($sock === false) {
            throw new \RuntimeException("connect failed: {$errstr}");
        }

        try {
            stream_set_timeout($sock, (int) ceil($timeout));
            $handshake = "\x00" . self::varint(-1) . self::string($hostname) . pack('n', $port) . self::varint(1);
            fwrite($sock, self::varint(strlen($handshake)) . $handshake . "\x01\x00");

            $length = self::readVarint($sock);
            if ($length < 1 || $length > self::MAX_BYTES) {
                throw new \RuntimeException('bad packet length');
            }
            $packet = self::readExactly($sock, $length);
            $offset = 0;
            if (self::varintFrom($packet, $offset) !== 0x00) {
                throw new \RuntimeException('unexpected packet');
            }
            $jsonLength = self::varintFrom($packet, $offset);
            $status = json_decode(substr($packet, $offset, $jsonLength), true);
            if (! is_array($status)) {
                throw new \RuntimeException('bad status JSON');
            }

            return $status;
        } finally {
            fclose($sock);
        }
    }

    /** Chat component (string, object or list) to plain text, colour codes removed. */
    public static function plainText(mixed $component): string
    {
        if (is_string($component)) {
            $text = $component;
        } elseif (is_array($component) && array_is_list($component)) {
            $text = implode('', array_map(self::plainText(...), $component));
        } elseif (is_array($component)) {
            $text = (string) ($component['text'] ?? '') . self::plainText($component['extra'] ?? []);
        } else {
            $text = '';
        }

        return (string) preg_replace('/\x{00A7}./u', '', $text);
    }

    private static function varint(int $value): string
    {
        $value &= 0xFFFFFFFF;
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= chr($value !== 0 ? $byte | 0x80 : $byte);
        } while ($value !== 0);

        return $out;
    }

    private static function string(string $s): string
    {
        return self::varint(strlen($s)) . $s;
    }

    /** @param resource $sock */
    private static function readVarint($sock): int
    {
        $value = 0;
        for ($i = 0; $i < 5; $i++) {
            $byte = ord(self::readExactly($sock, 1));
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
        throw new \RuntimeException('varint too long');
    }

    private static function varintFrom(string $buf, int &$offset): int
    {
        $value = 0;
        for ($i = 0; $i < 5 && $offset < strlen($buf); $i++) {
            $byte = ord($buf[$offset++]);
            $value |= ($byte & 0x7F) << (7 * $i);
            if (($byte & 0x80) === 0) {
                return $value;
            }
        }
        throw new \RuntimeException('bad varint');
    }

    /** @param resource $sock */
    private static function readExactly($sock, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('connection closed or timed out');
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
