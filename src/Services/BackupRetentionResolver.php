<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Schema;
use ServerSuite\Models\ModManagerGlobalSetting;
use ServerSuite\Models\ModManagerSetting;

/**
 * Resolves the retention target (keep-N) for a server's rolling backups.
 *
 * Precedence (highest first), mirroring the settings-center pattern used for
 * install paths and repo toggles:
 *   1. Explicit override passed by the caller (e.g. the "rotate now" UI form).
 *   2. Per-server setting — a `backup_keep` column on mod_manager_settings.
 *      Read defensively: if the column doesn't exist yet (the per-server
 *      retention migration is a later build step), this tier is simply skipped.
 *   3. Global default — mod_manager_global_settings 'backup_keep_default',
 *      set from the admin settings page.
 *   4. Config fallback — config('serversuite.backups.keep_default').
 *
 * Every DB read is wrapped so a missing table/column during install or on a
 * fresh panel falls through to config rather than breaking rotation.
 */
class BackupRetentionResolver
{
    public const PER_SERVER_COLUMN = 'backup_keep';
    public const GLOBAL_KEY        = 'backup_keep_default';

    public function resolve(string $serverUuid, ?int $override = null): int
    {
        if ($override !== null) {
            return $override;
        }

        $perServer = $this->perServerKeep($serverUuid);
        if ($perServer !== null) {
            return $perServer;
        }

        $global = $this->globalKeep();
        if ($global !== null) {
            return $global;
        }

        return (int) config('serversuite.backups.keep_default', 5);
    }

    // -------------------------------------------------------------------------

    protected function perServerKeep(string $serverUuid): ?int
    {
        try {
            if (! Schema::hasColumn('mod_manager_settings', self::PER_SERVER_COLUMN)) {
                return null;
            }

            $row = ModManagerSetting::query()
                ->where('server_uuid', $serverUuid)
                ->first();

            $value = $row?->{self::PER_SERVER_COLUMN};

            return ($value === null || $value === '') ? null : (int) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function globalKeep(): ?int
    {
        try {
            $value = ModManagerGlobalSetting::getValue(self::GLOBAL_KEY);

            return ($value === null || $value === '') ? null : (int) $value;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
