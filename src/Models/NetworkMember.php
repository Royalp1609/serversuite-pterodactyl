<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A backend in a Velocity network (#30). `name` is its key in velocity.toml
 * [servers]; `position` is its place in the try list.
 *
 * @property int $id
 * @property int $network_id
 * @property string $server_uuid
 * @property string $name
 * @property int $position
 * @property ?string $setup_status ok | action | failed | unsupported, from the last Apply
 */
class NetworkMember extends Model
{
    protected $table = 'server_suite_network_members';

    protected $fillable = ['network_id', 'server_uuid', 'name', 'position', 'setup_status'];

    protected $casts = ['network_id' => 'integer', 'position' => 'integer'];
}
