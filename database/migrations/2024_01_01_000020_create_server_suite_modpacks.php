<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modpacks (#23): the pack installed on a server (one per server) and the
 * install/update in progress. `files` lists every file the pack put on the
 * server, so an update can remove what the new version dropped and hand-added
 * files can be told apart. Mod jars the pack installed are also linked from
 * server_mods.modpack_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_suite_modpacks', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36)->unique();
            $table->string('source')->default('modrinth');

            // Installed pack (null until the first install finishes)
            $table->string('project_id')->nullable();
            $table->string('version_id')->nullable();
            $table->string('name')->nullable();
            $table->string('version_number')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('minecraft')->nullable();
            $table->string('loader')->nullable();
            $table->string('loader_version')->nullable();
            $table->json('files')->nullable();
            $table->timestamp('installed_at')->nullable();

            // Current or last run: installing|installed|failed
            $table->string('status')->default('installed');
            $table->string('phase')->nullable();
            $table->json('target')->nullable();
            $table->json('plan')->nullable();
            $table->unsignedInteger('progress_done')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->json('failures')->nullable();
            $table->json('state')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('requested_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('server_mods', function (Blueprint $table) {
            $table->unsignedBigInteger('modpack_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('server_mods', function (Blueprint $table) {
            $table->dropIndex(['modpack_id']);
            $table->dropColumn('modpack_id');
        });
        Schema::dropIfExists('server_suite_modpacks');
    }
};
