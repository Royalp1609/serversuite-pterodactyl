<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Test stand-in for the panel's Egg model, so a loader switch (modpacks
 * stage 2, #23) runs under Testbench. Only name, docker images and
 * variables. Tables come from panelTables() in tests/Pest.php.
 */
class Egg extends Model
{
    protected $table = 'eggs';

    protected $guarded = [];

    protected $casts = ['docker_images' => 'array'];

    public function variables(): HasMany
    {
        return $this->hasMany(EggVariable::class);
    }
}
