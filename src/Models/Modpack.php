<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The modpack installed on a server, and the install/update running or last
 * run (#23). One row per server.
 *
 * @property int $id
 * @property string $server_uuid
 * @property string $source
 * @property string|null $project_id
 * @property string|null $version_id
 * @property string|null $name
 * @property string|null $version_number
 * @property string|null $icon_url
 * @property string|null $minecraft
 * @property string|null $loader
 * @property string|null $loader_version
 * @property array<int, array{path: string, sha1: string, size: int}>|null $files
 * @property string $status        installing|installed|failed
 * @property string|null $phase    stopping|backing_up|staging|files|overrides|finishing|done
 * @property array|null $target    the pack version being installed
 * @property array|null $plan      {install: [...], remove: [...], index_sha1: string}
 * @property int $progress_done
 * @property int $progress_total
 * @property array<int, array{path: string, reason: string}>|null $failures
 * @property array|null $state     job bookkeeping (stop sent, backup uuid, attempts)
 * @property string|null $error
 */
class Modpack extends Model
{
    public const STATUS_INSTALLING = 'installing';
    public const STATUS_INSTALLED  = 'installed';
    public const STATUS_FAILED     = 'failed';

    protected $table = 'server_suite_modpacks';

    protected $guarded = ['id'];

    protected $casts = [
        'files'          => 'array',
        'target'         => 'array',
        'plan'           => 'array',
        'failures'       => 'array',
        'state'          => 'array',
        'progress_done'  => 'integer',
        'progress_total' => 'integer',
        'requested_by'   => 'integer',
        'installed_at'   => 'datetime',
        'started_at'     => 'datetime',
        'finished_at'    => 'datetime',
    ];

    public static function forServer(string $uuid): ?self
    {
        return static::query()->where('server_uuid', $uuid)->first();
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_INSTALLING;
    }

    public function hasInstalledPack(): bool
    {
        return $this->project_id !== null;
    }

    /** @param array<string, mixed> $state */
    public function mergeState(array $state): void
    {
        $this->state = array_merge($this->state ?? [], $state);
    }
}
