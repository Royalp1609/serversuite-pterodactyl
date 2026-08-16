<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $batch_id
 * @property string $server_uuid
 * @property string $source
 * @property string $source_id
 * @property string $mod_name
 * @property string $version_id
 * @property string|null $version_number
 * @property string $kind            primary|dependency
 * @property string $status          pending|running|success|failed|skipped
 * @property string|null $error
 * @property int|null $requested_by
 */
class ModInstallTask extends Model
{
    protected $table = 'mod_install_tasks';

    protected $fillable = [
        'batch_id',
        'server_uuid',
        'source',
        'source_id',
        'mod_name',
        'version_id',
        'version_number',
        'kind',
        'status',
        'error',
        'requested_by',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function scopeForBatch($query, string $batchId)
    {
        return $query->where('batch_id', $batchId);
    }

    public function markRunning(): void
    {
        $this->update(['status' => self::STATUS_RUNNING]);
    }

    public function markSuccess(): void
    {
        $this->update(['status' => self::STATUS_SUCCESS, 'error' => null]);
    }

    public function markFailed(string $error): void
    {
        $this->update(['status' => self::STATUS_FAILED, 'error' => $error]);
    }

    public function markSkipped(string $reason): void
    {
        $this->update(['status' => self::STATUS_SKIPPED, 'error' => $reason]);
    }
}
