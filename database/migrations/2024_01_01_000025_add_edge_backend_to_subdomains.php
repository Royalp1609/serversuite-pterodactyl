<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Edge mode (#2): the internal host:port a subdomain's route on the edge
 * router points at. Null = a direct subdomain (records to the server's own
 * address and port).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_suite_subdomains', 'edge_backend')) {
            Schema::table('server_suite_subdomains', function (Blueprint $table) {
                $table->string('edge_backend', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_suite_subdomains', 'edge_backend')) {
            Schema::table('server_suite_subdomains', function (Blueprint $table) {
                $table->dropColumn('edge_backend');
            });
        }
    }
};
