<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `source` was an enum of the first repositories (modrinth, curseforge,
 * hangar, manual), so a new source such as GeyserMC (#28) was refused by the
 * database after its file had already been delivered. Plain strings now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_mods', fn (Blueprint $t) => $t->string('source', 32)->change());
        Schema::table('mod_install_tasks', fn (Blueprint $t) => $t->string('source', 32)->change());
    }

    public function down(): void
    {
        // Rows from newer sources would not fit the old enum; left as strings.
    }
};
