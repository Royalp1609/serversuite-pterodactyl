<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #30 Velocity networks: one proxy server and the backend servers behind it,
 * all owned by the same user. A server is in at most one network.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('server_suite_networks')) {
            Schema::create('server_suite_networks', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('owner_id')->index();
                $table->string('proxy_uuid')->unique();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('server_suite_network_members')) {
            Schema::create('server_suite_network_members', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('network_id')->index();
                $table->string('server_uuid')->unique();
                $table->string('name', 32);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['network_id', 'name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('server_suite_network_members');
        Schema::dropIfExists('server_suite_networks');
    }
};
