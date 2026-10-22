<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;

/** Test stand-in for the panel's Node model (see Server). */
class Node extends Model
{
    protected $table = 'nodes';

    protected $guarded = [];
}
