<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domains a panel admin offers for subdomains (Cloudflare zones). The API
 * token is stored encrypted (Laravel 'encrypted' cast) and never returned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_suite_domains', function (Blueprint $table) {
            $table->id();
            $table->string('zone')->unique();
            $table->string('zone_id')->nullable();
            $table->text('token');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_suite_domains');
    }
};
