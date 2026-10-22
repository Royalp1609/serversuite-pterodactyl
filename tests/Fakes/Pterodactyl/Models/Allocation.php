<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/** Test stand-in for the panel's Allocation model (see Server). */
class Allocation extends Model
{
    protected $table = 'allocations';

    protected $guarded = [];

    protected $casts = ['port' => 'integer'];
}
