<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cloudflare token health per admin domain (#139): what Cloudflare last said
 * about the token, when it expires, and the last warning sent, so an expired
 * token is reported once instead of failing every claim in silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_suite_domains', 'token_status')) {
            Schema::table('server_suite_domains', function (Blueprint $table) {
                $table->string('token_status', 16)->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->timestamp('token_checked_at')->nullable();
                $table->string('token_warned', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('server_suite_domains', 'token_status')) {
            Schema::table('server_suite_domains', function (Blueprint $table) {
                $table->dropColumn(['token_status', 'token_expires_at', 'token_checked_at', 'token_warned']);
            });
        }
    }
};
