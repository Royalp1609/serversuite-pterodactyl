<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_mods', function (Blueprint $table) {
            $table->id();

            // Pterodactyl/Pelican server identifier (UUID string, not FK to allow
            // the plugin to work without a hard schema coupling to the panel tables)
            $table->string('server_uuid', 36)->index();

            // Where this mod came from
            $table->enum('source', ['modrinth', 'curseforge', 'hangar', 'manual'])
                  ->default('modrinth');

            // The repository's own identifier for this mod (slug or numeric id)
            $table->string('source_id')->index();

            // Human-readable information
            $table->string('mod_name');
            $table->string('mod_slug')->nullable();

            // Installed state
            $table->string('installed_version');
            $table->string('game_version')->nullable();
            $table->string('loader')->nullable();       // fabric, forge, paper, etc.
            $table->string('target_filename');          // the actual .jar/.zip filename on server
            $table->string('install_path')->nullable(); // /mods, /plugins, /addons

            // Dependency graph — stored as JSON array of source_id strings
            $table->json('dependencies')->nullable();

            // Audit
            $table->unsignedBigInteger('installed_by')->nullable(); // user id
            $table->timestamps();

            $table->unique(['server_uuid', 'source', 'source_id'], 'server_mod_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_mods');
    }
};
