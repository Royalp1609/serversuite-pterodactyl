<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #26 notifications: the server's own targets (Discord webhooks, ntfy
 * topics) and event switches, encrypted at rest. Null = the admin default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mod_manager_settings', 'notify')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->text('notify')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', fn (Blueprint $t) => $t->dropColumn('notify'));
    }
};
