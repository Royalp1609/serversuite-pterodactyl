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
 * @property string|null $token_status active, expired, disabled or invalid (#139); null = not checked yet
 * @property \Illuminate\Support\Carbon|null $token_expires_at
 * @property \Illuminate\Support\Carbon|null $token_checked_at
 * @property string|null $token_warned the last warning sent, so each is sent once
 */
class SubdomainDomain extends Model
{
    protected $table = 'server_suite_domains';

    protected $fillable = ['zone', 'zone_id', 'token', 'enabled'];

    protected $hidden = ['token'];

    protected $casts = [
        'token'   => 'encrypted',
        'enabled' => 'boolean',
        'token_expires_at' => 'datetime',
        'token_checked_at' => 'datetime',
    ];
}
