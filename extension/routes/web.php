<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\serversuite\ModManagerController;

/*
| Mod Manager extension routes. Blueprint auto-prefixes every path with
| /extensions/serversuite/. The React tab calls these via @/api/http.
| Each handler authorizes via the installed ServerModPolicy and delegates
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

// Subdomains (#14). Admin routes first so /admin isn't taken as a {server}.
Route::post('/admin/domains', [ModManagerController::class, 'adminAddDomain'])
    ->name('extension.serversuite.admin-add-domain');
Route::post('/admin/domains/{id}/toggle', [ModManagerController::class, 'adminToggleDomain'])
    ->whereNumber('id')->name('extension.serversuite.admin-toggle-domain');
Route::post('/admin/domains/{id}/remove', [ModManagerController::class, 'adminRemoveDomain'])
    ->whereNumber('id')->name('extension.serversuite.admin-remove-domain');
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
