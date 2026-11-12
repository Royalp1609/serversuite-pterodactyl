<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes Server Suite's per-server rows (mod manifest, pending updates,
 * settings, install tasks, modpack, backup mod lists, network membership)
 * for servers that no longer exist, so a deleted server leaves nothing
 * behind (#149): its settings row kept scheduled backups retrying it forever
 * and held its own-domain DNS token. Subdomains are left alone: their DNS
 * records need the provider, which the subdomain code handles, and so is the
 * settings row of a server that still has subdomains (it may hold the token).
 */
class ServerDataCleaner
{
    /** Tables keyed by server_uuid, children first. */
    private const TABLES = ['mod_install_tasks', 'server_suite_network_members', 'server_suite_modpacks', 'server_suite_backup_mod_lists', 'server_mods', 'mod_manager_settings'];

    /** @return array<string, int> rows removed per table */
    public function forServer(string $uuid): array
    {
        return $this->delete(fn ($q) => $q->where('server_uuid', $uuid));
    }

    /**
     * Rows whose server is gone. With $dryRun nothing is deleted.
     *
     * @return array<string, int>
     */
    public function orphans(bool $dryRun = false): array
    {
        $live = DB::table('servers')->select('uuid');

        return $this->delete(fn ($q) => $q->whereNotIn('server_uuid', $live), $dryRun);
    }

    /** @return array<string, int> */
    private function delete(callable $scope, bool $dryRun = false): array
    {
        $out = [];
        DB::transaction(function () use ($scope, $dryRun, &$out) {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $q = $scope(DB::table($table));
                if ($table === 'server_mods' && Schema::hasTable('server_mod_pending_updates')) {
                    $ids = (clone $q)->pluck('id');
                    $out['server_mod_pending_updates'] = $dryRun
                        ? DB::table('server_mod_pending_updates')->whereIn('server_mod_id', $ids)->count()
                        : DB::table('server_mod_pending_updates')->whereIn('server_mod_id', $ids)->delete();
                }
                // Settings can hold a server's own-domain DNS token, which the
                // subdomain cleanup needs; keep them until its subdomains are gone.
                if ($table === 'mod_manager_settings' && Schema::hasTable('server_suite_subdomains')) {
                    $q->whereNotIn('server_uuid', DB::table('server_suite_subdomains')->select('server_uuid'));
                }
                $out[$table] = $dryRun ? $q->count() : $q->delete();
            }
        });

        return $out;
    }
}
