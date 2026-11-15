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
        $t->boolean('maintenance_mode')->default(false);
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
        $t->unsignedBigInteger('egg_id')->nullable();
        $t->boolean('skip_scripts')->default(false);
        $t->timestamps();
    });
    $schema->create('eggs', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->text('docker_images')->nullable();
        $t->timestamps();
    });
    $schema->create('egg_variables', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('egg_id');
        $t->string('env_variable');
        $t->string('default_value')->nullable();
        $t->string('rules')->nullable();
        $t->timestamps();
    });
    $schema->create('server_variables', function (Illuminate\Database\Schema\Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('server_id');
        $t->unsignedBigInteger('variable_id');
        $t->string('variable_value')->nullable();
        $t->timestamps();
    });
}
