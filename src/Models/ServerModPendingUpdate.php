<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $server_mod_id
 * @property string $available_version
 * @property string|null $download_url
 * @property string|null $changelog
 * @property bool   $dismissed
 */
class ServerModPendingUpdate extends Model
{
    protected $table = 'server_mod_pending_updates';

    protected $fillable = [
        'server_mod_id',
        'available_version',
        'download_url',
        'changelog',
        'dismissed',
    ];

    protected $casts = [
        'dismissed' => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function serverMod(): BelongsTo
    {
        return $this->belongsTo(ServerMod::class, 'server_mod_id');
    }
}
