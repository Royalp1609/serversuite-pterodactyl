<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A domain the panel admin offers for subdomains. The token is encrypted at
 * rest and hidden from serialisation; it never leaves the server.
 *
 * @property int $id
 * @property string $zone
 * @property string|null $zone_id
 * @property string $token
 * @property bool $enabled
 */
class SubdomainDomain extends Model
{
    protected $table = 'server_suite_domains';

    protected $fillable = ['zone', 'zone_id', 'token', 'enabled'];

    protected $hidden = ['token'];

    protected $casts = [
        'token'   => 'encrypted',
        'enabled' => 'boolean',
    ];
}
