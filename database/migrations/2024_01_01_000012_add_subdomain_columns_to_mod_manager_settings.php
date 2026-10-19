<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-server subdomain allowance (admin-set, 0 = none) and the server's own
 * Cloudflare domain (token encrypted, write-only). Kept in the mod's table,
 * not the panel's `servers` table, so panel upgrades can't clash with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            $table->unsignedInteger('subdomain_limit')->default(0);
            $table->string('own_dns_zone')->nullable();
            $table->string('own_dns_zone_id')->nullable();
            $table->text('own_dns_token')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mod_manager_settings', function (Blueprint $table) {
            $table->dropColumn(['subdomain_limit', 'own_dns_zone', 'own_dns_zone_id', 'own_dns_token']);
        });
    }
};
