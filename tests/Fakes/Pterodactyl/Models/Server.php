<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Test stand-in for the panel's Server model, so the provider registers its
 * server hooks under Testbench. Only what the plugin touches: uuid, status,
 * node and primary allocation. Tables come from panelTables() in tests/Pest.php.
 */
class Server extends Model
{
    protected $table = 'servers';

    protected $guarded = [];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }
}
