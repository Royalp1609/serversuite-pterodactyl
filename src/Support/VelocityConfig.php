<?php

namespace ServerSuite\Support;

use ServerSuite\Support\Config\ConfigEditor;
use ServerSuite\Support\Config\TomlFormat;

/**
 * Edits velocity.toml for a network (#30). The [servers] table and its
 * `try` list are rewritten (they hold one key per backend, so the value
 * editor can't add or drop them); [forced-hosts] keeps only the hosts whose
 * servers still exist, because Velocity refuses to start when a forced host
 * names a missing server. Everything else goes through ConfigEditor, so the
 * rest of the file stays as it is. The result must read back as TOML with
 * exactly the wanted servers, or nothing is returned.
 */
final class VelocityConfig
{
    private const HEADER = '/^\s*\[\s*([^\[\]]+?)\s*\]\s*(#.*)?$/';

    /**
     * @param array<string, string> $servers name => "host:port", in try order
     *
     * @throws \RuntimeException
     */
    public static function apply(string $raw, array $servers): string
    {
        if ($servers === []) {
            throw new \RuntimeException('A network needs at least one backend server.');
        }
        $nl = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n/', $raw) ?: [];

        $lines = self::rewriteServers($lines, $servers, $nl);
        $lines = self::pruneForcedHosts($lines, array_keys($servers));
        $out = implode($nl, $lines);

        // Modern forwarding: the backends check the secret.
        $form = ConfigEditor::open($out, 'velocity.toml');
        $mode = collect($form['fields'])->firstWhere('label', 'player-info-forwarding-mode');
        if ($mode === null) {
            throw new \RuntimeException('velocity.toml has no player-info-forwarding-mode setting. Start the proxy once with a current Velocity.');
        }
        $out = ConfigEditor::save($out, 'velocity.toml', [$mode['id'] => 'MODERN'])['content'];

        self::verify($out, $servers);

        return $out;
    }

    /**
     * The last backend left: no servers, no try list and no forced hosts, so
     * the proxy doesn't keep sending players to a server that left.
     *
     * @throws \RuntimeException
     */
    public static function clear(string $raw): string
    {
        $nl = str_contains($raw, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n/', $raw) ?: [];
        $out = implode($nl, self::pruneForcedHosts(self::rewriteServers($lines, [], $nl), []));

        self::verify($out, []);

        return $out;
    }

    /** The secret file velocity.toml names, relative to the server root. */
    public static function secretFile(string $raw): string
    {
        $f = collect(ConfigEditor::open($raw, 'velocity.toml')['fields'])->firstWhere('label', 'forwarding-secret-file');
        $name = is_array($f) ? trim((string) $f['value']) : '';

        return preg_match('#^[\w.\-]+$#', $name) ? $name : 'forwarding.secret';
    }

    /**
     * @param list<string> $lines
     * @param array<string, string> $servers
     *
     * @return list<string>
     */
    private static function rewriteServers(array $lines, array $servers, string $nl): array
    {
        [$start, $end] = self::section($lines, 'servers');

        $lead = [];
        $tryComments = [];
        if ($start !== null) {
            // Comments straight after the header, and the ones above `try`.
            for ($i = $start + 1; $i < $end && preg_match('/^\s*#/', $lines[$i]); $i++) {
                $lead[] = $lines[$i];
            }
            for ($i = $start + 1; $i < $end; $i++) {
                if (preg_match('/^\s*try\s*=/', $lines[$i])) {
                    for ($j = $i - 1; $j > $start + count($lead) && preg_match('/^\s*#/', $lines[$j]); $j--) {
                        array_unshift($tryComments, $lines[$j]);
                    }
                    break;
                }
            }
        }

        $section = array_merge(['[servers]'], $lead);
        foreach ($servers as $name => $address) {
            $section[] = $name . ' = ' . json_encode($address, JSON_UNESCAPED_SLASHES);
        }
        $section[] = '';
        $section = array_merge($section, $tryComments ?: ['# In what order we should try servers when a player logs in or is kicked from a server.']);
        // One line: the same TOML, and it reads back for the check below.
        $section[] = 'try = [' . implode(', ', array_map(fn ($n) => json_encode($n), array_keys($servers))) . ']';
        $section[] = '';

        if ($start === null) {
            return array_merge(self::trimEnd($lines), [''], $section);
        }

        return array_merge(array_slice($lines, 0, $start), $section, array_slice($lines, $end));
    }

    /**
     * Drop forced hosts that name a server the network doesn't have.
     *
     * @param list<string> $lines
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function pruneForcedHosts(array $lines, array $names): array
    {
        [$start, $end] = self::section($lines, 'forced-hosts');
        if ($start === null) {
            return $lines;
        }
        $keep = [];
        for ($i = $start + 1; $i < $end; $i++) {
            if (! preg_match('/^\s*("[^"]*"|[\w.\-]+)\s*=\s*\[/', $lines[$i])) {
                $keep[] = $lines[$i];
                continue;
            }
            $entry = [$lines[$i]];
            $depth = substr_count($lines[$i], '[') - substr_count($lines[$i], ']');
            while ($depth > 0 && $i + 1 < $end) {
                $entry[] = $lines[++$i];
                $depth += substr_count($lines[$i], '[') - substr_count($lines[$i], ']');
            }
            $text = implode("\n", $entry);
            preg_match_all('/"([^"]*)"/', substr($text, strpos($text, '=') + 1), $m);
            if ($m[1] !== [] && array_diff($m[1], $names) === []) {
                $keep = array_merge($keep, $entry);
            }
        }

        return array_merge(array_slice($lines, 0, $start + 1), $keep, array_slice($lines, $end));
    }

    /**
     * Line range of a table: [header index, index of the next header or the end].
     *
     * @param list<string> $lines
     *
     * @return array{0: ?int, 1: int}
     */
    private static function section(array $lines, string $name): array
    {
        $start = null;
        foreach ($lines as $i => $line) {
            if (preg_match(self::HEADER, $line, $m)) {
                if ($start !== null) {
                    return [$start, $i];
                }
                if (trim($m[1], " \"'") === $name) {
                    $start = $i;
                }
            }
        }

        return [$start, count($lines)];
    }

    /** @param list<string> $lines @return list<string> */
    private static function trimEnd(array $lines): array
    {
        while ($lines !== [] && trim(end($lines)) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * @param array<string, string> $servers
     *
     * @throws \RuntimeException
     */
    private static function verify(string $out, array $servers): void
    {
        $slots = TomlFormat::scan($out, 'toml');
        if ($slots === null) {
            throw new \RuntimeException("The edited velocity.toml didn't read back as TOML, so it wasn't written.");
        }
        $got = [];
        $try = null;
        foreach ($slots as $s) {
            if (($s['path'][0] ?? null) === 'servers' && count($s['path']) === 2) {
                $s['path'][1] === 'try' ? $try = $s['value'] : $got[$s['path'][1]] = $s['value'];
            }
        }
        if ($got !== $servers || $try !== array_keys($servers)) {
            throw new \RuntimeException("The edited velocity.toml didn't hold the expected servers, so it wasn't written.");
        }
    }
}
