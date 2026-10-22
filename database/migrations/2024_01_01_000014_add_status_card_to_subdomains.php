<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #29 status cards: the owner's per-subdomain switch for the public
 * status page and badge. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_suite_subdomains', 'status_public')) {
            Schema::table('server_suite_subdomains', function (Blueprint $table) {
                $table->boolean('status_public')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('server_suite_subdomains', fn (Blueprint $t) => $t->dropColumn('status_public'));
    }
};
