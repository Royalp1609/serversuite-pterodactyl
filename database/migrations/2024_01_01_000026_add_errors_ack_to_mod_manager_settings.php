<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Errors tab acknowledge: what was on the tab when someone acknowledged it
 * (log error groups by key, first-seen time and count; the time for crash
 * reports), so only errors and crashes after that stand out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mod_manager_settings', 'errors_ack')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->json('errors_ack')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mod_manager_settings', 'errors_ack')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->dropColumn('errors_ack');
            });
        }
    }
};
