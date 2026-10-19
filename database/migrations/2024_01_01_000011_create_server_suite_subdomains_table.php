<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subdomains claimed by servers. domain_id null = the server's own domain.
 * ip/port record what the DNS points at, so a changed allocation shows as stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_suite_subdomains', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid')->index();
            $table->unsignedBigInteger('domain_id')->nullable()->index();
            $table->string('provider')->default('cloudflare');
            $table->string('label');
            $table->string('zone');
            $table->string('fqdn')->unique();
            $table->string('a_record_id')->nullable();
            $table->string('srv_record_id')->nullable();
            $table->string('ip')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_suite_subdomains');
    }
};
