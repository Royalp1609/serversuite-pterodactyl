<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #37: when a subdomain's server got a new primary allocation. DNS moves
 * once the server has started after this (the new port is live then).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_suite_subdomains', 'move_pending_since')) {
            Schema::table('server_suite_subdomains', function (Blueprint $table) {
                $table->timestamp('move_pending_since')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('server_suite_subdomains', fn (Blueprint $t) => $t->dropColumn('move_pending_since'));
    }
};
