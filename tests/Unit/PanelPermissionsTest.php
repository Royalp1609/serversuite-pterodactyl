<?php

use Pterodactyl\Models\Permission;
use ServerSuite\Support\PanelPermissions;

/** #17: Server Suite on Pterodactyl's own subuser permissions. */

/** A user the way Pterodactyl's ServerPolicy sees one: root/owner pass, subusers hold a list. */
function permUser(array $granted, bool $owner = false): object
{
    return new class($granted, $owner) {
        public function __construct(private array $granted, private bool $owner) {}

        public function can(string $ability, object $server): bool
        {
            return $this->owner || in_array($ability, $this->granted, true);
        }
    };
}

/** "group.key" strings the panel's subuser filter keeps (SubuserController::getDefaultPermissions). */
function grantable(): array
{
    return Permission::permissions()->map(fn ($v, $prefix) => array_map(fn ($k) => "{$prefix}.{$k}", array_keys($v['keys'])))->flatten()->all();
}

it('adds the mods and subdomains groups to the panel list, once, keeping the built-in ones', function () {
    expect(PanelPermissions::register())->toBeTrue();
    expect(PanelPermissions::register())->toBeTrue();

    $all = Permission::permissions();
    expect($all->keys()->all())->toContain('backup', 'startup', 'mods', 'subdomains')
        ->and(array_keys($all['mods']['keys']))->toBe(['read', 'install', 'update', 'remove', 'configure'])
        ->and(grantable())->toContain('mods.read', 'subdomains.manage', 'backup.read');
});

it('maps every client endpoint of the extension controller', function () {
    $src = file_get_contents(__DIR__ . '/../../extension/app/ModManagerController.php');
    preg_match_all('/public function (\w+)\(Request \$request, string \$server/', $src, $m);

    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $endpoint) {
        expect(PanelPermissions::ENDPOINTS)->toHaveKey($endpoint);
    }
    expect(substr_count($src, 'authorizeEndpoint($request, $srv, __FUNCTION__)'))->toBe(count($m[1]), 'each endpoint checks itself');
});

it('only asks for permissions a subuser can actually be granted', function () {
    PanelPermissions::register();
    $grantable = grantable();

    foreach (PanelPermissions::ENDPOINTS as $endpoint => $perms) {
        foreach (array_diff($perms, ['owner', 'member']) as $p) {   // pseudo-permissions, checked by PanelPermissions itself
            expect($grantable)->toContain($p);
        }
    }
});

it('lets owners do everything and subusers exactly what they hold', function () {
    $server = (object) ['id' => 1];

    expect(PanelPermissions::allows(permUser([], owner: true), $server, 'serverVersionNuclear'))->toBeTrue()
        ->and(PanelPermissions::allows(permUser(['backup.read']), $server, 'backups'))->toBeTrue()
        ->and(PanelPermissions::allows(permUser(['backup.read']), $server, 'backupsRotate'))->toBeFalse()
        ->and(PanelPermissions::allows(permUser(['backup.create']), $server, 'backupsRotate'))->toBeFalse('rotation also deletes')
        ->and(PanelPermissions::allows(permUser(['backup.create', 'backup.delete']), $server, 'backupsRotate'))->toBeTrue()
        ->and(PanelPermissions::allows(permUser(['startup.update', 'startup.docker-image', 'settings.reinstall']), $server, 'serverVersionApply'))->toBeFalse('needs backup.create for the pre-switch backup')
        ->and(PanelPermissions::allows(permUser(['mods.read']), $server, 'install'))->toBeFalse()
        ->and(PanelPermissions::allows(permUser(['mods.install']), $server, 'modpackInstall'))->toBeFalse('a pack install also removes mods and stops the server')
        ->and(PanelPermissions::allows(permUser(['mods.install', 'mods.remove', 'control.stop']), $server, 'modpackInstall'))->toBeTrue()
        ->and(PanelPermissions::allows(permUser(['mods.read']), $server, 'modpackPreview'))->toBeTrue()
        ->and(PanelPermissions::allows(permUser(['subdomains.read']), $server, 'subdomainsClaim'))->toBeFalse()
        ->and(PanelPermissions::allows(permUser(['mods.read']), $server, 'noSuchEndpoint'))->toBeFalse()
        ->and(PanelPermissions::allows(null, $server, 'backups'))->toBeFalse();
});

it('hides each tab behind the permission its endpoints use', function () {
    $yml = file_get_contents(__DIR__ . '/../../extension/resources/scripts/components/Components.yml');

    // Blueprint pastes Permission into routes.ts as-is, after its YAML parser
    // drops the outer quotes, so the value must carry its own JS quotes:
    // '"mods.*"' -> permission: "mods.*" (a bare mods.* fails the build).
    foreach (PanelPermissions::TABS as $tab => $perm) {
        expect($yml)->toMatch("/Name: '{$tab}', Permission: '\"" . preg_quote($perm, '/') . "\"'/");
    }
    expect(preg_match_all("/Permission: '[^\"]/", $yml))->toBe(0, 'a Permission without JS quotes');

    // Pterodactyl's own routes match first, so a tab on one of their paths
    // shows their page instead (our Velocity tab sat on /network).
    preg_match_all("/Path: '([^']+)'/", $yml, $m);
    expect(array_intersect($m[1], ['/', '/files', '/databases', '/schedules', '/users', '/backups', '/network', '/startup', '/settings', '/activity']))->toBe([]);
});

it('needs the same permissions to set up automatic upgrades as to switch by hand (#111)', function () {
    expect(PanelPermissions::required('autoUpgradeState'))->toBe(['startup.read'])
        ->and(PanelPermissions::required('autoUpgradeSave'))->toBe(PanelPermissions::required('serverVersionApply'));
});

it('only lets a backup delete one to make room for a user who may delete backups (#130)', function () {
    $src = file_get_contents(__DIR__ . '/../../extension/app/ModManagerController.php');
    foreach (['backupsCreate', 'prechange'] as $endpoint) {
        $fn = substr($src, strpos($src, "public function {$endpoint}("));
        $fn = substr($fn, 0, strpos($fn, "\n    }\n"));
        expect($fn)->toContain("PanelPermissions::holds(\$request->user(), \$srv, ['backup.delete'])");
    }

    $modpacks = file_get_contents(__DIR__ . '/../../src/Services/ModpackService.php');
    expect(substr_count($modpacks, "'may_evict' => \$this->mayEvict(\$server, \$userId)"))->toBe(2, 'install and remove');
    expect(file_get_contents(__DIR__ . '/../../src/Services/ModpackInstaller.php'))->toContain("(bool) (\$state['may_evict'] ?? true)");
});
