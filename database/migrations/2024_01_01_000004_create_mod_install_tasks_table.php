<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_install_tasks', function (Blueprint $table) {
            $table->id();

            // Groups tasks dispatched together (one batch = one user action).
            $table->uuid('batch_id')->index();

            $table->string('server_uuid', 36)->index();

            // What this task installs.
            $table->enum('source', ['modrinth', 'curseforge', 'hangar', 'manual']);
            $table->string('source_id');
            $table->string('mod_name');
            $table->string('version_id');
            $table->string('version_number')->nullable();

            // Why it's in the batch: primary requested mod vs a pulled-in dependency.
            $table->enum('kind', ['primary', 'dependency'])->default('primary');

            // Lifecycle.
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped'])
                  ->default('pending')
                  ->index();
            $table->text('error')->nullable();

            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_install_tasks');
    }
};
