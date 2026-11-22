<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\serversuite\ModManagerController;

/*
| Mod Manager extension routes. Blueprint auto-prefixes every path with
| /extensions/serversuite/. The React tab calls these via @/api/http.
| Each handler checks Pterodactyl subuser permissions (PanelPermissions, #17) and delegates
| to ServerSuite\Services\ModManagerService.
|
| The {server} segment is the server UUID, passed from the React tab's
| ServerContext (matches the proven reference extension's body-param style,
| keeping us on Blueprint's simple web router).
*/

// Read endpoints
Route::post('/admin/settings', [ModManagerController::class, 'adminSaveSettings'])
    ->name('extension.serversuite.admin-settings');
Route::post('/admin/import-egg', [ModManagerController::class, 'adminImportEgg'])
    ->name('extension.serversuite.admin-import-egg');
Route::get('/admin/egg-catalog', [ModManagerController::class, 'adminEggCatalog'])
    ->name('extension.serversuite.admin-egg-catalog');
Route::post('/admin/import-catalog', [ModManagerController::class, 'adminImportCatalogEgg'])
    ->name('extension.serversuite.admin-import-catalog');
Route::post('/admin/update-eggs', [ModManagerController::class, 'adminUpdateAllEggs'])
    ->name('extension.serversuite.admin-update-eggs');

// Which game the server runs (#77): the sidebar hides tabs by it.
Route::get('/{server}/game', [ModManagerController::class, 'game'])
    ->name('extension.serversuite.game');

// Backup before mod changes (#24): the clearance a mod change needs.
Route::get('/{server}/prechange', [ModManagerController::class, 'prechangeMode'])
    ->name('extension.serversuite.prechange-mode');
Route::post('/{server}/prechange/mode', [ModManagerController::class, 'prechangeSetMode'])
    ->name('extension.serversuite.prechange-set-mode');
Route::post('/{server}/prechange', [ModManagerController::class, 'prechange'])
    ->name('extension.serversuite.prechange');
Route::get('/{server}/prechange/{token}', [ModManagerController::class, 'prechangeState'])
    ->where('token', '[a-f0-9]{32}')->name('extension.serversuite.prechange-state');

// After a backup restore (#60): what changed in the mod list.
Route::get('/{server}/restore-sync', [ModManagerController::class, 'restoreSync'])
    ->name('extension.serversuite.restore-sync');
Route::post('/{server}/restore-sync/dismiss', [ModManagerController::class, 'restoreSyncDismiss'])
    ->name('extension.serversuite.restore-sync-dismiss');

// Errors tab (#87)
Route::get('/{server}/errors', [ModManagerController::class, 'errors'])
    ->name('extension.serversuite.errors');
Route::get('/{server}/errors/crash', [ModManagerController::class, 'errorsCrash'])
    ->name('extension.serversuite.errors-crash');

// Crash help (#25)
Route::get('/{server}/crash', [ModManagerController::class, 'crash'])
    ->name('extension.serversuite.crash');
Route::post('/{server}/crash/dismiss', [ModManagerController::class, 'crashDismiss'])
    ->name('extension.serversuite.crash-dismiss');
Route::post('/{server}/mods/{serverMod}/disable', [ModManagerController::class, 'crashDisable'])
    ->whereNumber('serverMod')->name('extension.serversuite.mod-disable');
Route::post('/{server}/mods/{serverMod}/enable', [ModManagerController::class, 'crashEnable'])
    ->whereNumber('serverMod')->name('extension.serversuite.mod-enable');

// Bedrock crossplay (#28): Geyser + Floodgate.
Route::get('/{server}/crossplay', [ModManagerController::class, 'crossplay'])
    ->name('extension.serversuite.crossplay');
Route::post('/{server}/crossplay/install', [ModManagerController::class, 'crossplayInstall'])
    ->name('extension.serversuite.crossplay-install');
Route::post('/{server}/crossplay/configure', [ModManagerController::class, 'crossplayConfigure'])
    ->name('extension.serversuite.crossplay-configure');

// Velocity networks (#30): the owner's servers behind a Velocity proxy.
Route::get('/{server}/network', [ModManagerController::class, 'network'])
    ->name('extension.serversuite.network');
Route::post('/{server}/network', [ModManagerController::class, 'networkApply'])
    ->name('extension.serversuite.network-apply');

// Configs (#21): browse /plugins, /config, ... and edit files (text or form).
Route::get('/{server}/configs', [ModManagerController::class, 'configs'])
    ->name('extension.serversuite.configs');
Route::get('/{server}/configs/file', [ModManagerController::class, 'configsOpen'])
    ->name('extension.serversuite.configs-open');
Route::post('/{server}/configs/render', [ModManagerController::class, 'configsRender'])
    ->name('extension.serversuite.configs-render');
Route::post('/{server}/configs/file', [ModManagerController::class, 'configsSave'])
    ->name('extension.serversuite.configs-save');

// Properties (#20): server.properties as a form; restart or world reset after saving.
Route::get('/{server}/properties', [ModManagerController::class, 'properties'])
    ->name('extension.serversuite.properties');
Route::post('/{server}/properties', [ModManagerController::class, 'propertiesSave'])
    ->name('extension.serversuite.properties-save');
Route::post('/{server}/properties/reset-world', [ModManagerController::class, 'propertiesResetWorld'])
    ->name('extension.serversuite.properties-reset-world');

// Modpacks (#23): Modrinth .mrpack install and update.
Route::get('/{server}/modpacks', [ModManagerController::class, 'modpacks'])
    ->name('extension.serversuite.modpacks');
Route::get('/{server}/modpacks/search', [ModManagerController::class, 'modpacksSearch'])
    ->name('extension.serversuite.modpacks-search');
Route::get('/{server}/modpacks/{project}/versions', [ModManagerController::class, 'modpackVersions'])
    ->name('extension.serversuite.modpack-versions');
Route::post('/{server}/modpacks/preview', [ModManagerController::class, 'modpackPreview'])
    ->name('extension.serversuite.modpack-preview');
Route::post('/{server}/modpacks/install', [ModManagerController::class, 'modpackInstall'])
    ->name('extension.serversuite.modpack-install');
Route::post('/{server}/modpacks/remove', [ModManagerController::class, 'modpackRemove'])
    ->name('extension.serversuite.modpack-remove');

// Players (#27): whitelist, ops and bans.
// Bedrock add-ons (#93)
Route::get('/{server}/addons', [ModManagerController::class, 'addons'])
    ->name('extension.serversuite.addons');
Route::post('/{server}/addons/folder', [ModManagerController::class, 'addonsFolder'])
    ->name('extension.serversuite.addons-folder');
Route::post('/{server}/addons/install', [ModManagerController::class, 'addonsInstall'])
    ->name('extension.serversuite.addons-install');
Route::post('/{server}/addons/remove', [ModManagerController::class, 'addonsRemove'])
    ->name('extension.serversuite.addons-remove');
Route::post('/{server}/addons/enable', [ModManagerController::class, 'addonsEnable'])
    ->name('extension.serversuite.addons-enable');
Route::post('/{server}/addons/move', [ModManagerController::class, 'addonsMove'])
    ->name('extension.serversuite.addons-move');
Route::post('/{server}/addons/texturepack', [ModManagerController::class, 'addonsTexturepack'])
    ->name('extension.serversuite.addons-texturepack');

Route::get('/{server}/players', [ModManagerController::class, 'players'])
    ->name('extension.serversuite.players');
Route::post('/{server}/players', [ModManagerController::class, 'playersAdd'])
    ->name('extension.serversuite.players-add');
Route::post('/{server}/players/remove', [ModManagerController::class, 'playersRemove'])
    ->name('extension.serversuite.players-remove');
Route::post('/{server}/players/whitelist', [ModManagerController::class, 'playersWhitelist'])
    ->name('extension.serversuite.players-whitelist');

// Notifications (#26): this server's Discord / ntfy targets and events.
Route::get('/{server}/notifications', [ModManagerController::class, 'notifications'])
    ->name('extension.serversuite.notifications');
Route::post('/{server}/notifications', [ModManagerController::class, 'notificationsAdd'])
    ->name('extension.serversuite.notifications-add');
Route::post('/{server}/notifications/remove', [ModManagerController::class, 'notificationsRemove'])
    ->name('extension.serversuite.notifications-remove');
Route::post('/{server}/notifications/events', [ModManagerController::class, 'notificationsEvents'])
    ->name('extension.serversuite.notifications-events');
Route::post('/{server}/notifications/test', [ModManagerController::class, 'notificationsTest'])
    ->name('extension.serversuite.notifications-test');
Route::post('/admin/notifications', [ModManagerController::class, 'adminNotify'])
    ->name('extension.serversuite.admin-notifications');

// Subdomains (#14). Admin routes first so /admin isn't taken as a {server}.
Route::post('/admin/domains', [ModManagerController::class, 'adminAddDomain'])
    ->name('extension.serversuite.admin-add-domain');
Route::post('/admin/domains/{id}/toggle', [ModManagerController::class, 'adminToggleDomain'])
    ->whereNumber('id')->name('extension.serversuite.admin-toggle-domain');
Route::post('/admin/domains/{id}/token', [ModManagerController::class, 'adminReplaceDomainToken'])
    ->whereNumber('id')->name('extension.serversuite.admin-replace-domain-token');
Route::post('/admin/domains/{id}/remove', [ModManagerController::class, 'adminRemoveDomain'])
    ->whereNumber('id')->name('extension.serversuite.admin-remove-domain');
Route::post('/admin/edge', [ModManagerController::class, 'adminEdge'])
    ->name('extension.serversuite.admin-edge');
Route::post('/admin/egg-games', [ModManagerController::class, 'adminSaveEggGames'])
    ->name('extension.serversuite.admin-egg-games');
Route::post('/admin/subdomain-allowances', [ModManagerController::class, 'adminSaveAllowances'])
    ->name('extension.serversuite.admin-subdomain-allowances');

Route::get('/{server}/subdomains', [ModManagerController::class, 'subdomains'])
    ->name('extension.serversuite.subdomains');
Route::post('/{server}/subdomains', [ModManagerController::class, 'subdomainsClaim'])
    ->name('extension.serversuite.subdomains-claim');
Route::post('/{server}/subdomains/own', [ModManagerController::class, 'subdomainsSaveOwn'])
    ->name('extension.serversuite.subdomains-own');
Route::post('/{server}/subdomains/own/remove', [ModManagerController::class, 'subdomainsRemoveOwn'])
    ->name('extension.serversuite.subdomains-own-remove');
Route::post('/{server}/subdomains/{id}/release', [ModManagerController::class, 'subdomainsRelease'])
    ->whereNumber('id')->name('extension.serversuite.subdomains-release');
Route::post('/{server}/subdomains/{id}/repoint', [ModManagerController::class, 'subdomainsRepoint'])
    ->whereNumber('id')->name('extension.serversuite.subdomains-repoint');
Route::post('/{server}/subdomains/{id}/status-card', [ModManagerController::class, 'subdomainsStatusCard'])
    ->whereNumber('id')->name('extension.serversuite.subdomains-status-card');

Route::get('/{server}/repositories', [ModManagerController::class, 'repositories'])
    ->name('extension.serversuite.repositories');

Route::get('/{server}/reconcile', [ModManagerController::class, 'reconcile'])
    ->name('extension.serversuite.reconcile');

Route::post('/{server}/reconcile/adopt', [ModManagerController::class, 'reconcileAdopt'])
    ->name('extension.serversuite.reconcile-adopt');

Route::post('/{server}/reconcile/forget', [ModManagerController::class, 'reconcileForget'])
    ->name('extension.serversuite.reconcile-forget');

Route::get('/{server}/game-versions', [ModManagerController::class, 'gameVersions'])
    ->name('extension.serversuite.game-versions');

Route::get('/{server}/installed', [ModManagerController::class, 'installed'])
    ->name('extension.serversuite.installed');

Route::post('/{server}/search', [ModManagerController::class, 'search'])
    ->name('extension.serversuite.search');

Route::post('/{server}/versions', [ModManagerController::class, 'versions'])
    ->name('extension.serversuite.versions');
Route::get('/{server}/server-versions', [ModManagerController::class, 'serverVersions'])
    ->name('extension.serversuite.server-versions');
Route::post('/{server}/version-preflight', [ModManagerController::class, 'serverVersionPreflight'])
    ->name('extension.serversuite.version-preflight');
Route::post('/{server}/version-apply', [ModManagerController::class, 'serverVersionApply'])
    ->name('extension.serversuite.version-apply');
Route::get('/{server}/auto-upgrade', [ModManagerController::class, 'autoUpgradeState'])
    ->name('extension.serversuite.auto-upgrade');
Route::post('/{server}/auto-upgrade', [ModManagerController::class, 'autoUpgradeSave'])
    ->name('extension.serversuite.auto-upgrade-save');
Route::get('/{server}/server-status', [ModManagerController::class, 'serverStatus'])
    ->name('extension.serversuite.server-status');
Route::post('/{server}/version-nuclear', [ModManagerController::class, 'serverVersionNuclear'])
    ->name('extension.serversuite.version-nuclear');
Route::get('/{server}/loaders', [ModManagerController::class, 'loaders'])
    ->name('extension.serversuite.loaders');
Route::post('/{server}/loader-switch', [ModManagerController::class, 'loaderSwitch'])
    ->name('extension.serversuite.loader-switch');

Route::post('/{server}/details', [ModManagerController::class, 'details'])
    ->name('extension.serversuite.details');

Route::post('/{server}/plan', [ModManagerController::class, 'plan'])
    ->name('extension.serversuite.plan');

// Write endpoints
Route::post('/{server}/install', [ModManagerController::class, 'install'])
    ->name('extension.serversuite.install');
// Bulk remove: several mods behind one pre-change backup.
Route::post('/{server}/mods/remove-batch', [ModManagerController::class, 'removeBatch'])
    ->name('extension.serversuite.remove-batch');

// The install queue: several mods behind one pre-change backup.
Route::post('/{server}/install-batch', [ModManagerController::class, 'installBatch'])
    ->name('extension.serversuite.install-batch');

Route::get('/{server}/tasks/{batch}', [ModManagerController::class, 'tasks'])
    ->name('extension.serversuite.tasks');

Route::post('/{server}/mods/{serverMod}/update', [ModManagerController::class, 'update'])
    ->name('extension.serversuite.update');

Route::post('/{server}/update-all', [ModManagerController::class, 'updateAll'])
    ->name('extension.serversuite.update-all');

Route::delete('/{server}/mods/{serverMod}', [ModManagerController::class, 'destroy'])
    ->name('extension.serversuite.destroy');

// Backups tab — list + per-server settings + rotate/lock/delete. Thin
// delegators to the backend PanelServerOps seam and BackupRotationService.
Route::get('/{server}/backups', [ModManagerController::class, 'backups'])
    ->name('extension.serversuite.backups');

Route::post('/{server}/backups/settings', [ModManagerController::class, 'backupsSaveSettings'])
    ->name('extension.serversuite.backups-settings');

Route::post('/{server}/backups/rotate', [ModManagerController::class, 'backupsRotate'])
    ->name('extension.serversuite.backups-rotate');

Route::post('/{server}/backups/create', [ModManagerController::class, 'backupsCreate'])
    ->name('extension.serversuite.backups-create');

Route::post('/{server}/backups/{backup}/rename', [ModManagerController::class, 'backupsRename'])
    ->name('extension.serversuite.backups-rename');

Route::get('/{server}/backups/{backup}/download', [ModManagerController::class, 'backupsDownload'])
    ->name('extension.serversuite.backups-download');

Route::post('/{server}/backups/{backup}/restore', [ModManagerController::class, 'backupsRestore'])
    ->name('extension.serversuite.backups-restore');

Route::post('/{server}/backups/{backup}/lock', [ModManagerController::class, 'backupsLock'])
    ->name('extension.serversuite.backups-lock');

Route::delete('/{server}/backups/{backup}', [ModManagerController::class, 'backupsDelete'])
    ->name('extension.serversuite.backups-delete');
