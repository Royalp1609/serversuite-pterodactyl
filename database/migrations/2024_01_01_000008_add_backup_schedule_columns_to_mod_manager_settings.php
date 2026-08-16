<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server backup-schedule override (Phase 1.5).
 *
 *   backup_interval_hours — how often THIS server is backed up by the
 *                           scheduler, in hours. NULL falls back to the global
 *                           default interval. Only bounded presets are written
 *                           (6/12/24/48/168) so the per-server cadence can never
 *                           drop below the panel backup throttle window.
 *   backup_last_run_at    — when the scheduler last rotated this server. Used
 *                           for due-tracking: the tick rotates a server only
 *                           once its interval has elapsed since this stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('mod_manager_settings', 'backup_interval_hours')) {
                $table->unsignedSmallInteger('backup_interval_hours')->nullable();
            }

            if (! Schema::hasColumn('mod_manager_settings', 'backup_last_run_at')) {
                $table->timestamp('backup_last_run_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            foreach (['backup_interval_hours', 'backup_last_run_at'] as $column) {
                if (Schema::hasColumn('mod_manager_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
