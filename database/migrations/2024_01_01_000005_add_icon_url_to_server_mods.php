<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_mods', function (Blueprint $table) {
            $table->string('icon_url')->nullable()->after('mod_slug');
            $table->string('page_url')->nullable()->after('icon_url');
        });
    }

    public function down(): void
    {
        Schema::table('server_mods', function (Blueprint $table) {
            $table->dropColumn(['icon_url', 'page_url']);
        });
    }
};
