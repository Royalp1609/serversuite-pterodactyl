<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/** Test stand-in for the panel's EggVariable model (see Egg). */
class EggVariable extends Model
{
    protected $table = 'egg_variables';

    protected $guarded = [];
}
