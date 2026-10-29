<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;

/**
 * Players for Steam games (#85; Pelican #89): Rust's owners, moderators and bans, and
 * Valheim's admin, banned and permitted lists, by SteamID.
 *
 * Rust: while the server runs, changes go in as console commands (ownerid,
 * removeowner, banid, unban, ...) followed by server.writecfg, so they apply
 * at once and Rust writes server/rust/cfg/users.cfg and bans.cfg itself.
 * While it's stopped those files are edited through Wings (Rust reads them
 * on start). The egg fixes the identity to "rust".
 *
 * Valheim reads its lists on start and has no console, so they are always
 * file edits that apply on the next start. Its manual asks for Platform
 * User IDs, "Steam_<SteamID64>" (case sensitive); a bare SteamID64 gets the
 * prefix. Names aren't looked up (no Steam Web API key yet).
 *
 * While the server is starting or stopping, changes are refused: Rust reads
 * its cfg files early in a boot that takes minutes and writes them again on
 * the way down, so a file edit then would be read too late or written over.
 */
class SteamPlayersService
{
    private const RUST_CFG = '/server/rust/cfg';
    private const VALHEIM_DIR = '/.config/unity3d/IronGate/Valheim';
    private const MAX_READ = 1048576;
    private const STEAM_ID = '/^7656119\d{10}$/';

    /** game => list => what it is and where it lives */
    public const LISTS = [
        'rust' => [
            'owners' => ['label' => 'Owners (admins)', 'file' => self::RUST_CFG . '/users.cfg', 'key' => 'ownerid', 'remove' => 'removeowner'],
            'moderators' => ['label' => 'Moderators', 'file' => self::RUST_CFG . '/users.cfg', 'key' => 'moderatorid', 'remove' => 'removemoderator'],
            'bans' => ['label' => 'Banned', 'file' => self::RUST_CFG . '/bans.cfg', 'key' => 'banid', 'remove' => 'unban'],
        ],
        'valheim' => [
            'admins' => ['label' => 'Admins', 'file' => self::VALHEIM_DIR . '/adminlist.txt', 'header' => '// List admin players ID  ONE per line'],
            'banned' => ['label' => 'Banned', 'file' => self::VALHEIM_DIR . '/bannedlist.txt', 'header' => '// List banned players ID  ONE per line'],
            'permitted' => ['label' => 'Permitted', 'file' => self::VALHEIM_DIR . '/permittedlist.txt', 'header' => '// List permitted players ID ONE per line'],
        ],
    ];

    /** @var (callable(object, string): void)|null test hook: send a console command */
    private $sender;
    /** @var (callable(object): (bool|string))|null test hook: running (bool) or Wings' state */
    private $running;

    public function __construct(protected DaemonFileClient $daemon, ?callable $sender = null, ?callable $running = null)
    {
        $this->sender = $sender;
        $this->running = $running;
    }

    public static function supports(string $game): bool
    {
        return isset(self::LISTS[$game]);
    }

    /**
     * @return array{running: bool, lists: array<string, list<array{id: string, name: string, reason: string}>>}
     */
    public function overview(object $server, string $game): array
    {
        $lists = [];
        foreach (self::LISTS[$game] ?? [] as $key => $list) {
            $lists[$key] = $this->rows($server, $game, $list);
        }

        return ['running' => $this->isRunning($server), 'lists' => $lists];
    }

    /**
     * Add a player to a list.
     *
     * @throws \RuntimeException with a message for the user
     */
    public function add(object $server, string $game, string $listKey, string $who, string $name = '', string $reason = ''): string
    {
        $list = self::LISTS[$game][$listKey] ?? throw new \RuntimeException('Unknown list.');

        if ($game === 'rust') {
            $id = $this->steamId($who);
            $name = $this->text($name, 'Name');
            $reason = $this->text($reason, 'Reason');
            if ($this->settledState($server) === 'running') {
                $this->send($server, "{$list['key']} {$id} \"{$name}\" \"{$reason}\"");
                $this->send($server, 'server.writecfg');

                return "{$id} added.";
            }
            $this->editRust($server, $list, function (array $lines) use ($list, $id, $name, $reason) {
                $lines = $this->withoutRust($lines, $list['key'], $id);
                $lines[] = "{$list['key']} {$id} \"{$name}\" \"{$reason}\"" . ($list['key'] === 'banid' ? ' -1' : '');

                return $lines;
            });

            return "{$id} added. It applies when the server starts.";
        }

        $id = $this->platformId($who);
        $running = $this->settledState($server) === 'running';
        $before = $this->rows($server, $game, $list);
        if (in_array($id, array_column($before, 'id'), true)) {
            return "{$id} is already on the list.";
        }
        $this->editValheim($server, $list, fn (array $ids) => [...$ids, $id]);
        $note = $listKey === 'permitted' && $before === []
            ? ' From then on only players on the permitted list can join.'
            : '';

        return "{$id} added. " . $this->valheimApplies($running) . $note;
    }

    /** @throws \RuntimeException */
    public function remove(object $server, string $game, string $listKey, string $who): string
    {
        $list = self::LISTS[$game][$listKey] ?? throw new \RuntimeException('Unknown list.');

        if ($game === 'rust') {
            $id = $this->steamId($who);
            if ($this->settledState($server) === 'running') {
                $this->send($server, "{$list['remove']} {$id}");
                $this->send($server, 'server.writecfg');

                return "{$id} removed.";
            }
            $this->editRust($server, $list, fn (array $lines) => $this->withoutRust($lines, $list['key'], $id));

            return "{$id} removed. It applies when the server starts.";
        }

        $id = $this->platformId($who);
        $running = $this->settledState($server) === 'running';
        // Also an older bare SteamID line for the same player.
        $bare = str_starts_with($id, 'Steam_') ? substr($id, 6) : null;
        $this->editValheim($server, $list, fn (array $ids) => array_values(array_filter($ids, fn ($x) => $x !== $id && $x !== $bare)));

        return "{$id} removed. " . $this->valheimApplies($running);
    }

    // -------------------------------------------------------------------------

    /** Valheim reads its lists on start, and an in-game ban rewrites the file from what it loaded. */
    private function valheimApplies(bool $running): string
    {
        return $running
            ? 'Restart the server to apply it (until then an in-game ban or unban can overwrite the list).'
            : 'It applies when the server starts.';
    }

    /** @return list<array{id: string, name: string, reason: string}> */
    private function rows(object $server, string $game, array $list): array
    {
        $raw = $this->daemon->readFile($server->uuid, $list['file'], self::MAX_READ) ?? '';
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $t = trim($line);
            if ($game === 'rust') {
                if (preg_match('/^' . preg_quote($list['key'], '/') . '\s+(\d{17})(?:\s+"([^"]*)")?(?:\s+"([^"]*)")?/', $t, $m)) {
                    $out[] = ['id' => $m[1], 'name' => $m[2] ?? '', 'reason' => $m[3] ?? ''];
                }
            } elseif ($t !== '' && ! str_starts_with($t, '//')) {
                $out[] = ['id' => $t, 'name' => '', 'reason' => ''];
            }
        }

        return $out;
    }

    /** @param callable(list<string>): list<string> $edit */
    private function editRust(object $server, array $list, callable $edit): void
    {
        $raw = $this->daemon->readFile($server->uuid, $list['file'], self::MAX_READ) ?? '';
        $nl = str_contains($raw, "\r\n") || $raw === '' ? "\r\n" : "\n";   // Rust writes CRLF
        $lines = array_values(array_filter(preg_split('/\r\n|\r|\n/', $raw) ?: [], fn ($l) => trim($l) !== ''));
        $this->write($server, $list['file'], implode($nl, $edit($lines)) . $nl);
    }

    /** @param list<string> $lines */
    private function withoutRust(array $lines, string $key, string $id): array
    {
        return array_values(array_filter($lines, fn ($l) => ! preg_match('/^\s*' . preg_quote($key, '/') . '\s+' . $id . '\b/', $l)));
    }

    /** @param callable(list<string>): list<string> $edit the ids, comments kept aside */
    private function editValheim(object $server, array $list, callable $edit): void
    {
        $raw = $this->daemon->readFile($server->uuid, $list['file'], self::MAX_READ) ?? '';
        $comments = [];
        $ids = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $t = trim($line);
            if (str_starts_with($t, '//')) {
                $comments[] = $t;
            } elseif ($t !== '') {
                $ids[] = $t;
            }
        }
        $this->write($server, $list['file'], implode("\n", [...($comments ?: [$list['header']]), ...$edit($ids)]) . "\n");
    }

    /** A SteamID64 (Rust). @throws \RuntimeException */
    private function steamId(string $who): string
    {
        $id = (string) preg_replace('/^Steam_/', '', trim($who));
        if (! preg_match(self::STEAM_ID, $id)) {
            throw new \RuntimeException('Enter a SteamID64: 17 digits starting 7656119 (e.g. from steamid.io).');
        }

        return $id;
    }

    /** A Valheim Platform User ID: Steam_<SteamID64>, or another platform's Platform_ID as is. @throws \RuntimeException */
    private function platformId(string $who): string
    {
        $id = trim($who);
        if (preg_match(self::STEAM_ID, $id)) {
            return 'Steam_' . $id;
        }
        if (preg_match('/^Steam_(\d+)$/', $id, $m)) {
            return preg_match(self::STEAM_ID, $m[1]) ? $id : throw new \RuntimeException('A Steam ID is 17 digits starting 7656119.');
        }
        if (! preg_match('/^[A-Za-z]{2,16}_[A-Za-z0-9\-]{1,64}$/', $id)) {
            throw new \RuntimeException('Enter a SteamID64 (17 digits) or a Platform User ID like Steam_76561198000000000 (from the server log or the F2 panel in game).');
        }

        return $id;
    }

    /** A name or reason inside "..." on a console line: no quotes or control characters. */
    private function text(string $s, string $what): string
    {
        $s = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\]+/u', ' ', $s));
        if (mb_strlen($s) > 64) {
            throw new \RuntimeException("{$what}: 64 characters at most.");
        }

        return $s;
    }

    private function isRunning(object $server): bool
    {
        return $this->state($server) === 'running';
    }

    /**
     * Wings' state for a change: running or offline.
     *
     * @throws \RuntimeException while starting or stopping
     */
    private function settledState(object $server): string
    {
        $state = $this->state($server);
        if ($state === 'starting' || $state === 'stopping') {
            throw new \RuntimeException("The server is {$state}. Try again once it has " . ($state === 'starting' ? 'started' : 'stopped') . '.');
        }

        return $state;
    }

    /** Wings' state: running, starting, stopping or offline (also when Wings can't be asked). */
    private function state(object $server): string
    {
        if ($this->running !== null) {
            $r = ($this->running)($server);

            return is_string($r) ? $r : ($r ? 'running' : 'offline');
        }
        try {
            $d = app('\\Pterodactyl\\Repositories\\Wings\\DaemonServerRepository')->setServer($server)->getDetails();
        } catch (\Throwable) {
            return 'offline';
        }

        return (string) ($d['state'] ?? ($d['utilization']['state'] ?? 'offline'));
    }

    /** @throws \RuntimeException */
    private function send(object $server, string $command): void
    {
        try {
            $this->sender !== null
                ? ($this->sender)($server, $command)
                : app('\\Pterodactyl\\Repositories\\Wings\\DaemonCommandRepository')->setServer($server)->send($command);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The server didn\'t take the command: ' . $e->getMessage(), previous: $e);
        }
    }

    /** @throws \RuntimeException */
    private function write(object $server, string $path, string $content): void
    {
        if (! method_exists($this->daemon, 'writeFile')) {
            throw new \RuntimeException('This panel build cannot write files through the plugin.');
        }
        $this->daemon->writeFile($server->uuid, $path, $content);
    }
}
