<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Test stand-in for the panel's Server model, so the provider registers its
 * server hooks under Testbench. Only what the hooks touch: uuid and the
 * primary allocation. Tables come from tests/Feature/ServerHooksTest.php.
 */
class Server extends Model
{
    protected $table = 'servers';

    protected $guarded = [];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }
}
