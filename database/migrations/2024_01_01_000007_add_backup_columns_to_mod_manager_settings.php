<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server backup-rotation settings.
 *
 *   backup_rotation_enabled — opt a server into the rolling-backup scheduler.
 *                             Default OFF; the scheduler only rotates servers
 *                             that explicitly turn this on.
 *   backup_keep             — per-server retention target (keep-N). NULL falls
 *                             back to the global default, then config
 *                             (see BackupRetentionResolver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('mod_manager_settings', 'backup_rotation_enabled')) {
                $table->boolean('backup_rotation_enabled')->default(false);
            }

            if (! Schema::hasColumn('mod_manager_settings', 'backup_keep')) {
                $table->unsignedSmallInteger('backup_keep')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            foreach (['backup_rotation_enabled', 'backup_keep'] as $column) {
                if (Schema::hasColumn('mod_manager_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
