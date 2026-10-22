<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** #24: back up before mod changes: always | ask | never (null = ask). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mod_manager_settings', 'pre_change_backup')) {
            Schema::table('mod_manager_settings', function (Blueprint $table) {
                $table->string('pre_change_backup', 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', fn (Blueprint $t) => $t->dropColumn('pre_change_backup'));
    }
};
