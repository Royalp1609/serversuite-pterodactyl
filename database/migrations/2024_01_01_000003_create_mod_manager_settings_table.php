<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_manager_settings', function (Blueprint $table) {
            $table->id();

            // One settings row per server
            $table->string('server_uuid', 36)->unique()->index();

            // Which repositories are enabled for this server (subset of global config)
            $table->boolean('modrinth_enabled')->default(true);
            $table->boolean('curseforge_enabled')->default(true);
            $table->boolean('hangar_enabled')->default(true);

            // Loader type: auto-detected value stored here so it can be overridden
            $table->string('loader')->nullable();            // fabric | forge | paper | etc.
            $table->string('game_version')->nullable();      // e.g. 1.21.1
            $table->boolean('loader_locked')->default(false); // true = admin has manually set it

            // Directory overrides (null = use global defaults from config)
            $table->string('install_path')->nullable();

            // Update scheduler opt-in per server
            $table->boolean('auto_update_checks')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_manager_settings');
    }
};
