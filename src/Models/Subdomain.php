<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A subdomain claimed by a server: an A record (DNS only) plus a
 * _minecraft._tcp SRV record carrying the port.
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
 * @property \Illuminate\Support\Carbon|null $move_pending_since  new allocation not live yet (#37)
 */
class Subdomain extends Model
{
    protected $table = 'server_suite_subdomains';

    protected $fillable = [
        'server_uuid', 'domain_id', 'provider', 'label', 'zone', 'fqdn',
        'a_record_id', 'srv_record_id', 'ip', 'port', 'created_by', 'move_pending_since',
    ];

    protected $casts = [
        'domain_id'  => 'integer',
        'port'       => 'integer',
        'created_by' => 'integer',
        'move_pending_since' => 'datetime',
    ];
}
