<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The mod list as it was when Server Suite took a backup (#60), so a
 * restore of that backup can put it back. One row per backup.
 *
 * @property int $id
 * @property string $server_uuid
 * @property string $backup_uuid
 * @property array<int, array<string, mixed>> $mods      server_mods rows
 * @property array<string, mixed>|null $modpack          the installed pack, or null
 * @property array{loader: ?string, game_version: ?string, install_path: ?string}|null $settings
 */
class BackupModList extends Model
{
    protected $table = 'server_suite_backup_mod_lists';

    protected $guarded = ['id'];

    protected $casts = [
        'mods'     => 'array',
        'modpack'  => 'array',
        'settings' => 'array',
    ];
}
