<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Velocity network (#30): the proxy server and its backends.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $proxy_uuid
 * @property \Illuminate\Support\Carbon|null $applied_at
 */
class Network extends Model
{
    protected $table = 'server_suite_networks';

    protected $fillable = ['owner_id', 'proxy_uuid', 'applied_at'];

    protected $casts = ['owner_id' => 'integer', 'applied_at' => 'datetime'];

    public function members(): HasMany
    {
        return $this->hasMany(NetworkMember::class)->orderBy('position')->orderBy('id');
    }
}
