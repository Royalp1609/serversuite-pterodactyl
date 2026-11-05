<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a backend's setup finished at the last Apply, so its Velocity tab
 * doesn't say it's behind a proxy when it couldn't be configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_suite_network_members', 'setup_status')) {
            Schema::table('server_suite_network_members', function (Blueprint $table) {
                $table->string('setup_status', 16)->nullable()->after('position');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_suite_network_members', 'setup_status')) {
            Schema::table('server_suite_network_members', function (Blueprint $table) {
                $table->dropColumn('setup_status');
            });
        }
    }
};
