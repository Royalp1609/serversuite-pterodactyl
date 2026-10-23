<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mod list saved with every backup Server Suite takes (#60): the
 * server_mods rows, the modpack and the loader settings at that moment, so
 * restoring the backup can put the list back as it was. Deleted with the
 * backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_suite_backup_mod_lists', function (Blueprint $table) {
            $table->id();
            $table->string('server_uuid', 36)->index();
            $table->string('backup_uuid', 36)->unique();
            $table->longText('mods');
            $table->longText('modpack')->nullable();
            $table->text('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_suite_backup_mod_lists');
    }
};
