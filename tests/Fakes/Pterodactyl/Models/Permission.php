<?php

namespace Pterodactyl\Models;

use Illuminate\Support\Collection;

/** Test stand-in for the panel's Permission model: the same protected static list and accessor. */
class Permission
{
    protected static array $permissions = [
        'backup' => ['description' => 'Backups.', 'keys' => ['create' => '', 'read' => '', 'delete' => '', 'download' => '', 'restore' => '']],
        'startup' => ['description' => 'Startup.', 'keys' => ['read' => '', 'update' => '', 'docker-image' => '']],
        'settings' => ['description' => 'Settings.', 'keys' => ['rename' => '', 'reinstall' => '']],
        'file' => ['description' => 'Files.', 'keys' => ['create' => '', 'read' => '', 'read-content' => '', 'update' => '', 'delete' => '', 'archive' => '', 'sftp' => '']],
    ];

    public static function permissions(): Collection
    {
        return Collection::make(self::$permissions);
    }
}
