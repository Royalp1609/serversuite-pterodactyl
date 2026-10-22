<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #25 crash help: the last mod change on each server (to tell whether a
 * crash followed it), and mods disabled from the crash banner.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mod_manager_settings', 'last_change')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->json('last_change')->nullable();
            });
        }
        if (! Schema::hasColumn('server_mods', 'disabled_at')) {
            Schema::table('server_mods', function (Blueprint $table) {
                $table->timestamp('disabled_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', fn (Blueprint $t) => $t->dropColumn('last_change'));
        Schema::table('server_mods', fn (Blueprint $t) => $t->dropColumn('disabled_at'));
    }
};
