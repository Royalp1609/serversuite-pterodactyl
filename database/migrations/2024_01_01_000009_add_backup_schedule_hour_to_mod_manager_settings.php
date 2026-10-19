<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional fixed hour (0-23, panel timezone) for scheduled backups. Null keeps
 * the original behaviour: due once the interval has elapsed since the last run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('backup_schedule_hour')->nullable()->after('backup_interval_hours');
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            $table->dropColumn('backup_schedule_hour');
        });
    }
};
