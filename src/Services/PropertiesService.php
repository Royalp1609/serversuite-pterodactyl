<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Support\Config\ConfigEditor;

/**
 * The Properties tab (#20): server.properties as a form (the #21 editor:
 * toggles, numbers, choices; values changed in place, so comments and order
 * stay and a typo can't create a setting). After saving it can restart the
 * server, or, when level-seed changed, reset the world so the new seed
 * applies: stop first, wipe the world only once the server is offline, then
 * start. A running server never has its world deleted.
 */
class PropertiesService
{
    public const FILE = '/server.properties';
    private const MAX_BYTES = 262144;

    public function __construct(protected DaemonFileClient $files, protected PanelServerOps $ops, protected FileDeliveryService $delivery) {}

    /** @return array{found: bool, hash: ?string, fields: list<array<string, mixed>>, seed: ?string, power: ?string} */
    public function open(string $uuid): array
    {
        $raw = $this->files->readFile($uuid, self::FILE, self::MAX_BYTES);
        $power = $this->ops->runtimeState($uuid)['power'] ?? null;
        if ($raw === null) {
            return ['found' => false, 'hash' => null, 'fields' => [], 'seed' => null, 'power' => $power];
        }
        $fields = ConfigEditor::open($raw, 'server.properties')['fields'];

        return [
            'found' => true, 'hash' => sha1($raw), 'fields' => $fields, 'power' => $power,
            'seed' => collect($fields)->firstWhere('label', 'level-seed')['value'] ?? null,
        ];
    }

    /**
     * Save form values. $after: none | restart | reset_world (only when the
     * seed changed). Returns what happened next.
     *
     * @param array<int|string, mixed> $values
     *
     * @return array{changed: list<string>, seed_changed: bool, status: string}
     *
     * @throws \ServerSuite\Support\Config\ConfigEditException|\InvalidArgumentException
     */
    public function save(string $uuid, string $hash, array $values, string $after): array
    {
        $raw = $this->files->readFile($uuid, self::FILE, self::MAX_BYTES);
        if ($raw === null || ! hash_equals(sha1($raw), $hash)) {
            throw new \InvalidArgumentException('server.properties changed since you opened it (the server rewrites it on start). Reload and make the change again.');
        }
        $before = collect(ConfigEditor::open($raw, 'server.properties')['fields'])->firstWhere('label', 'level-seed')['value'] ?? null;
        $r = ConfigEditor::save($raw, 'server.properties', $values);
        $after_seed = collect(ConfigEditor::open($r['content'], 'server.properties')['fields'])->firstWhere('label', 'level-seed')['value'] ?? null;
        $seedChanged = $before !== $after_seed;
        if ($after === 'reset_world' && ! $seedChanged) {
            throw new \InvalidArgumentException('The world is only reset when the seed changes.');
        }
        if ($r['changed'] !== []) {
            $this->files->writeFile($uuid, self::FILE, $r['content']);
        }

        $status = match ($after) {
            'restart' => $this->signal($uuid, 'restart', 'restarting'),
            'reset_world' => $this->signal($uuid, 'stop', 'stopping'),
            default => 'saved',
        };

        return ['changed' => $r['changed'], 'seed_changed' => $seedChanged, 'status' => $status];
    }

    /**
     * The world-reset step after a seed change, called until it's done: waits
     * for the server to be offline, then wipes the world (plugins and configs
     * stay) and starts it.
     *
     * @return array{status: string}
     */
    public function resetWorldStep(string $uuid): array
    {
        if (($this->ops->runtimeState($uuid)['power'] ?? null) !== 'offline') {
            return ['status' => 'stopping'];
        }
        $this->delivery->wipeForNuclear($uuid, 'keep_plugins');
        $this->ops->power($uuid, 'start');

        return ['status' => 'done'];
    }

    private function signal(string $uuid, string $signal, string $status): string
    {
        $this->ops->power($uuid, $signal);

        return $status;
    }
}
