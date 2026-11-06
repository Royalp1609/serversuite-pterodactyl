<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in automatic version upgrades (#111). `auto_upgrade_run` is the upgrade
 * in flight, stored so a panel restart can pick it up at the next tick.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mod_manager_settings', 'auto_upgrade_enabled')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->boolean('auto_upgrade_enabled')->default(false);
                $table->string('auto_upgrade_range', 8)->default('line');
                $table->unsignedTinyInteger('auto_upgrade_hour')->default(4);
                $table->json('auto_upgrade_run')->nullable();
                $table->json('auto_upgrade_status')->nullable();
                $table->timestamp('auto_upgrade_checked_at')->nullable();
                $table->timestamp('auto_upgrade_paused_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('mod_manager_settings', 'auto_upgrade_enabled')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->dropColumn(['auto_upgrade_enabled', 'auto_upgrade_range', 'auto_upgrade_hour', 'auto_upgrade_run', 'auto_upgrade_status', 'auto_upgrade_checked_at', 'auto_upgrade_paused_at']);
            });
        }
    }
};
