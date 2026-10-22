<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int    $id
 * @property string $server_uuid
 * @property string $source            modrinth|curseforge|hangar|manual
 * @property string $source_id
 * @property string $mod_name
 * @property string|null $mod_slug
 * @property string $installed_version
 * @property string|null $game_version
 * @property string|null $loader
 * @property string $target_filename
 * @property string|null $install_path
 * @property array|null  $dependencies
 * @property int|null    $installed_by
 * @property int|null    $modpack_id    set when a modpack installed it (#23)
 */
class ServerMod extends Model
{
    use HasFactory;

    protected $table = 'server_mods';

    protected $fillable = [
        'server_uuid',
        'source',
        'source_id',
        'mod_name',
        'mod_slug',
        'icon_url',
        'page_url',
        'installed_version',
        'game_version',
        'loader',
        'target_filename',
        'install_path',
        'dependencies',
        'installed_by',
        'modpack_id',
        'disabled_at',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'disabled_at'  => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /** @return HasOne<ServerModPendingUpdate, $this> */
    public function pendingUpdate(): HasOne
    {
        return $this->hasOne(ServerModPendingUpdate::class, 'server_mod_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForServer($query, string $serverUuid)
    {
        return $query->where('server_uuid', $serverUuid);
    }

    public function scopeWithPendingUpdates($query)
    {
        return $query->whereHas('pendingUpdate', fn ($q) => $q->where('dismissed', false));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function hasPendingUpdate(): bool
    {
        return $this->pendingUpdate()->where('dismissed', false)->exists();
    }
}
