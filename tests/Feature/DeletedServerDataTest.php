<?php

use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\ServerDataCleaner;

/**
 * #149: a deleted server leaves no Server Suite rows behind. Its settings
 * row kept the scheduled backup retrying it every tick, and held its
 * own-domain DNS token.
 */
beforeEach(function () {
    panelTables();
});

/** A settings row with scheduled backups on, two mods and a finished task. */
function suiteData(string $uuid): void
{
    ModManagerSetting::forServer($uuid)->forceFill(['backup_rotation_enabled' => true])->save();
    foreach (['A', 'B'] as $id) {
        DB::table('server_mods')->insert(['server_uuid' => $uuid, 'source' => 'modrinth', 'source_id' => $id, 'mod_name' => $id, 'installed_version' => '1', 'target_filename' => "{$id}.jar", 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('mod_install_tasks')->insert(['batch_id' => '00000000-0000-0000-0000-00000000000b', 'server_uuid' => $uuid, 'source' => 'modrinth', 'source_id' => 'A', 'mod_name' => 'A', 'version_id' => 'v', 'kind' => 'primary', 'status' => 'success', 'created_at' => now(), 'updated_at' => now()]);
}

function suiteRows(string $uuid): int
{
    return DB::table('mod_manager_settings')->where('server_uuid', $uuid)->count()
        + DB::table('server_mods')->where('server_uuid', $uuid)->count()
        + DB::table('mod_install_tasks')->where('server_uuid', $uuid)->count();
}

it("removes a server's data once its delete commits, and leaves other servers alone", function () {
    $server = Server::query()->create(['uuid' => 'srv-gone']);
    Server::query()->create(['uuid' => 'srv-kept']);
    suiteData('srv-gone');
    suiteData('srv-kept');

    DB::transaction(function () use ($server) {
        $server->delete();
        expect(suiteRows('srv-gone'))->toBe(4, 'nothing happens before the commit');
    });

    expect(suiteRows('srv-gone'))->toBe(0)
        ->and(suiteRows('srv-kept'))->toBe(4);
});

it('keeps the data when the delete rolls back', function () {
    $server = Server::query()->create(['uuid' => 'srv-back']);
    suiteData('srv-back');

    try {
        DB::transaction(function () use ($server) {
            $server->delete();
            throw new RuntimeException('panel delete failed');
        });
    } catch (RuntimeException) {
    }

    expect(suiteRows('srv-back'))->toBe(4);
});

it('prune-orphans counts with --dry-run and removes what deleted servers left behind', function () {
    Server::query()->create(['uuid' => 'srv-live']);
    suiteData('srv-live');
    suiteData('ghost-1');

    $this->artisan('serversuite:prune-orphans', ['--dry-run' => true])
        ->expectsOutputToContain('would remove 2 rows from server_mods')
        ->assertExitCode(0);
    expect(suiteRows('ghost-1'))->toBe(4, 'a dry run deletes nothing');

    $this->artisan('serversuite:prune-orphans')
        ->expectsOutputToContain('removed 1 row from mod_manager_settings')
        ->assertExitCode(0);

    expect(suiteRows('ghost-1'))->toBe(0)
        ->and(suiteRows('srv-live'))->toBe(4);
});

it('the hourly cleanup also removes what earlier deletes left behind', function () {
    Server::query()->create(['uuid' => 'srv-live']);
    suiteData('srv-live');
    suiteData('ghost-2');

    $this->artisan('serversuite:retry-subdomain-cleanup')->assertExitCode(0);

    expect(suiteRows('ghost-2'))->toBe(0)
        ->and(suiteRows('srv-live'))->toBe(4);
});

it('keeps the settings of a deleted server that still has subdomains (they hold its DNS token)', function () {
    suiteData('ghost-3');
    DB::table('server_suite_subdomains')->insert(['server_uuid' => 'ghost-3', 'provider' => 'cloudflare', 'label' => 'x', 'zone' => 'example.com', 'fqdn' => 'x.example.com', 'created_at' => now(), 'updated_at' => now()]);

    app(ServerDataCleaner::class)->orphans();

    expect(DB::table('mod_manager_settings')->where('server_uuid', 'ghost-3')->count())->toBe(1)
        ->and(DB::table('server_mods')->where('server_uuid', 'ghost-3')->count())->toBe(0);
});

it('no longer tries a scheduled backup for a server that was deleted', function () {
    $server = Server::query()->create(['uuid' => 'srv-sched']);
    suiteData('srv-sched');
    $server->delete();

    $this->artisan('serversuite:rotate-backups')
        ->expectsOutputToContain('No servers are due')
        ->assertExitCode(0);
});
