<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A subdomain claimed by a server: an A record (DNS only) plus a
 * SRV record carrying the port for games that look one up (Minecraft:
 * _minecraft._tcp); srv_record_id is null for games that don't (#79).
 *
 * @property int $id
 * @property string $server_uuid
 * @property int|null $domain_id      null = the server's own domain
 * @property string $provider
 * @property string $label
 * @property string $zone
 * @property string $fqdn
 * @property string|null $a_record_id
 * @property string|null $srv_record_id
 * @property string|null $ip
 * @property int|null $port
 * @property int|null $created_by
 * @property bool $status_public            public status card switched on (#29)
 * @property \Illuminate\Support\Carbon|null $move_pending_since  new allocation not live yet (#37)
 */
class Subdomain extends Model
{
    protected $table = 'server_suite_subdomains';

    protected $fillable = [
        'server_uuid', 'domain_id', 'provider', 'label', 'zone', 'fqdn',
        'a_record_id', 'srv_record_id', 'ip', 'port', 'created_by', 'status_public', 'move_pending_since', 'edge_backend',
    ];

    protected $casts = [
        'domain_id'  => 'integer',
        'port'       => 'integer',
        'created_by' => 'integer',
        'status_public' => 'boolean',
        'move_pending_since' => 'datetime',
    ];
}
