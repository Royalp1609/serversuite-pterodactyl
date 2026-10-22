<?php

uses(ServerSuite\Tests\TestCase::class)->in('Feature', 'Unit');

/** The panel tables the stand-in models in tests/Fakes/Pterodactyl use. */
function panelTables(): void
{
    $schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
    $schema->create('nodes', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('fqdn');
        $t->timestamps();
    });
    $schema->create('allocations', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('node_id')->nullable();
        $t->string('ip');
        $t->string('ip_alias')->nullable();
        $t->unsignedInteger('port');
        $t->timestamps();
    });
    $schema->create('servers', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->string('uuid');
        $t->string('name')->default('Server');
        $t->unsignedInteger('owner_id')->default(1);
        $t->unsignedBigInteger('node_id')->nullable();
        $t->unsignedBigInteger('allocation_id')->nullable();
        $t->string('status')->nullable();
        $t->string('external_id')->nullable();
        $t->unsignedInteger('backup_limit')->default(0);
        $t->timestamps();
    });
}
