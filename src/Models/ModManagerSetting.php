<?php

namespace ServerSuite\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $server_uuid
 * @property bool   $modrinth_enabled
 * @property bool   $curseforge_enabled
 * @property bool   $hangar_enabled
 * @property string|null $loader
 * @property string|null $game_version
 * @property bool   $loader_locked
 * @property string|null $install_path
 * @property bool   $auto_update_checks
 */
class ModManagerSetting extends Model
{
    protected $table = 'mod_manager_settings';

    protected $fillable = [
        'server_uuid',
        'modrinth_enabled',
        'curseforge_enabled',
        'hangar_enabled',
        'loader',
        'game_version',
        'loader_locked',
        'install_path',
        'auto_update_checks',
        'backup_rotation_enabled',
        'backup_keep',
        'backup_interval_hours',
        'backup_schedule_hour',
        'backup_last_run_at',
        'subdomain_limit',
        'pre_change_backup',
        'last_change',
        'own_dns_zone',
        'own_dns_zone_id',
        'own_dns_token',
        'notify',
        'auto_upgrade_enabled',
        'auto_upgrade_range',
        'auto_upgrade_hour',
        'auto_upgrade_run',
        'auto_upgrade_status',
        'auto_upgrade_checked_at',
        'auto_upgrade_paused_at',
    ];

    /** The own-domain Cloudflare token is write-only: never serialised. */
    protected $hidden = ['own_dns_token', 'notify'];

    protected $casts = [
        'modrinth_enabled'   => 'boolean',
        'curseforge_enabled' => 'boolean',
        'hangar_enabled'     => 'boolean',
        'loader_locked'      => 'boolean',
        'auto_update_checks' => 'boolean',
        'backup_rotation_enabled' => 'boolean',
        'backup_keep'             => 'integer',
        'backup_interval_hours'   => 'integer',
        'backup_schedule_hour'    => 'integer',
        'backup_last_run_at'      => 'datetime',
        'subdomain_limit'         => 'integer',
        'last_change'             => 'array',
        'notify'                  => 'encrypted:array',
        'auto_upgrade_enabled'    => 'boolean',
        'auto_upgrade_hour'       => 'integer',
        'auto_upgrade_run'        => 'array',
        'auto_upgrade_status'     => 'array',
        'auto_upgrade_checked_at' => 'datetime',
        'auto_upgrade_paused_at'  => 'datetime',
        'own_dns_token'           => 'encrypted',
    ];

    // -------------------------------------------------------------------------
    // Static helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch (or create with defaults) the settings row for a given server.
     */
    /**
     * The server's settings without creating a row (#141/#139 item 61): an
     * unsaved row with the defaults when there is none. For reads.
     */
    public static function peek(string $serverUuid): self
    {
        return static::query()->where('server_uuid', $serverUuid)->first() ?? new self([
            'server_uuid'        => $serverUuid,
            'modrinth_enabled'   => config('serversuite.repositories.modrinth', true),
            'curseforge_enabled' => config('serversuite.repositories.curseforge', true),
            'hangar_enabled'     => config('serversuite.repositories.hangar', true),
            'auto_update_checks' => true,
        ]);
    }

    public static function forServer(string $serverUuid): self
    {
        return static::firstOrCreate(
            ['server_uuid' => $serverUuid],
            [
                'modrinth_enabled'   => config('serversuite.repositories.modrinth', true),
                'curseforge_enabled' => config('serversuite.repositories.curseforge', true),
                'hangar_enabled'     => config('serversuite.repositories.hangar', true),
                'auto_update_checks' => true,
            ]
        );
    }

    /**
     * Return the effective install path for this server — explicit override
     * first, then the global loader-based default, then '/mods'.
     */
    public function effectiveInstallPath(): string
    {
        if ($this->install_path) {
            return $this->install_path;
        }

        $directories = config('serversuite.mod_directories', []);

        return $directories[$this->loader] ?? $directories['default'] ?? '/mods';
    }

    /**
     * Return an array of the repository driver keys that are enabled.
     *
     * @return string[]
     */
    public function enabledRepositories(): array
    {
        $enabled = [];

        if ($this->modrinth_enabled)   $enabled[] = 'modrinth';
        if ($this->curseforge_enabled) $enabled[] = 'curseforge';
        if ($this->hangar_enabled)     $enabled[] = 'hangar';

        return $enabled;
    }
}
