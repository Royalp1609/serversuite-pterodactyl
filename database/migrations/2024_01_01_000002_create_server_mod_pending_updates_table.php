<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_mod_pending_updates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('server_mod_id')
                  ->constrained('server_mods')
                  ->cascadeOnDelete();

            // The version available upstream
            $table->string('available_version');

            // Full download URL for this version (cached to avoid re-querying the repo API)
            $table->string('download_url')->nullable();

            // Optional changelog snippet fetched from the repo
            $table->text('changelog')->nullable();

            // When the update was discovered and whether it has been dismissed by an admin
            $table->boolean('dismissed')->default(false);
            $table->timestamps();

            $table->unique('server_mod_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_mod_pending_updates');
    }
};
