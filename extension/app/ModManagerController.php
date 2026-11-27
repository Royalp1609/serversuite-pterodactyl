<?php

namespace Pterodactyl\BlueprintFramework\Extensions\serversuite;

use Illuminate\Http\Request;
use ServerSuite\Support\PanelPermissions;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\Server;

// These classes come from the installed Composer package
// (pterodactyl-serversuite/serversuite). The extension is a thin UI shell:
// it authorizes and delegates, holding no business logic of its own.
use ServerSuite\Services\ModManagerService;
use ServerSuite\Services\ReconciliationService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Models\ModManagerGlobalSetting;
use ServerSuite\Support\RepositoryManager;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModInstallTask;
use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Services\BackupRetentionResolver;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Exceptions\BackupException;

class ModManagerController extends Controller
{
    public function __construct(
        protected ModManagerService $mods,
        protected RepositoryManager $repositories,
    ) {}

    /**
     * GET /{server}/game-versions
     * Returns the Minecraft version list for the filter dropdown plus the
     * detected version to pre-select.
     */
    public function gameVersions(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => $this->mods->gameVersions($srv)]);
    }

    /**
     * POST /{server}/details   { source, mod_id }
     * Full mod info (description, loaders, game versions, page URL) plus its
     * versions, for the in-panel Details view. One call so the modal needs a
     * single request.
     */
    public function details(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'source'  => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'  => ['required', 'string', 'max:128'],
            'loader'  => ['nullable', 'string'],
            'version' => ['nullable', 'string'],
        ]);

        $driver = $this->repositories->driver($data['source']);
        $mod    = $driver->get($data['mod_id']);

        if (! $mod) {
            return response()->json(['error' => 'Mod not found.'], 404);
        }

        $versions = $this->mods->versionsFor(
            $data['source'], $data['mod_id'], $srv,
            $data['loader'] ?? null, $data['version'] ?? null,
        );

        return response()->json(['data' => [
            'mod'      => $mod->toArray(),
            'versions' => array_map(fn ($v) => $v->toArray(), $versions),
        ]]);
    }

    /**
     * POST /{server}/update-all
     * Applies every pending update for the server in one pass.
     */
    public function updateAll(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }
        $result = $this->mods->updateAll($srv);

        $this->audit($srv, 'mods-updated-all', ['updated' => is_array($result['updated'] ?? null) ? count($result['updated']) : null, 'backup' => $clearance['backup']]);
        $this->recordChange($srv, 'update all mods', $clearance);
        return response()->json(['data' => $result]);
    }

    /**
     * POST /admin/settings — save the global admin settings.
     * Root administrators only; values override config via the backend
     * provider's boot-time mapping.
     */
    public function adminSaveSettings(Request $request)
    {
        if (! $request->user()?->root_admin) {
            abort(403);
        }

        $data = $request->validate([
            'repo_modrinth_enabled'     => ['nullable'],
            'repo_curseforge_enabled'   => ['nullable'],
            'repo_hangar_enabled'       => ['nullable'],
            'curseforge_api_key'        => ['nullable', 'string', 'max:255'],
            'clear_curseforge_api_key'  => ['nullable'],
            // A cron expression; a bad one would break the schedule (item 91).
            'scheduler_interval'        => ['nullable', 'string', 'max:64', function ($attr, $value, $fail) {
                if (! \Cron\CronExpression::isValidExpression(trim((string) $value))) {
                    $fail('The update check schedule must be a cron expression, such as 0 3 * * *.');
                }
            }],
            'scheduler_auto_apply'      => ['nullable'],
            'auto_update_on_conflict'   => ['nullable'],
            'backup_rotation_enabled'   => ['nullable'],
            'backup_keep_default'       => ['nullable', 'integer', 'min:1', 'max:500'],
            'backup_default_interval_hours' => ['nullable', 'integer', \Illuminate\Validation\Rule::in((array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]))],
            'backup_timezone'           => ['nullable', 'timezone'],
            'auto_upgrades_enabled'     => ['nullable'],
            'auto_upgrades_min_age_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'release_check_enabled'     => ['nullable'],
        ]);

        // Checkboxes: each sends 0 (a hidden field) or 1, so a field that isn't
        // in the request at all is left as it was (item 92). Stored as '1'/'0'.
        foreach (['repo_modrinth_enabled', 'repo_curseforge_enabled', 'repo_hangar_enabled', 'auto_update_on_conflict', 'backup_rotation_enabled', 'auto_upgrades_enabled', 'release_check_enabled'] as $box) {
            if ($request->has($box)) {
                ModManagerGlobalSetting::setValue($box, $request->boolean($box) ? '1' : '0');
            }
        }

        if (isset($data['auto_upgrades_min_age_days'])) {
            ModManagerGlobalSetting::setValue('auto_upgrades_min_age_days', (string) (int) $data['auto_upgrades_min_age_days']);
        }

        // Auto-apply inverts to the stored/notify_only semantics.
        if ($request->has('scheduler_auto_apply')) {
            ModManagerGlobalSetting::setValue('scheduler_notify_only', $request->boolean('scheduler_auto_apply') ? '0' : '1');
        }

        if (! empty($data['scheduler_interval'])) {
            ModManagerGlobalSetting::setValue('scheduler_interval', trim($data['scheduler_interval']));
        }

        // Backup rotation globals.
        if (isset($data['backup_keep_default']) && $data['backup_keep_default'] !== null && $data['backup_keep_default'] !== '') {
            ModManagerGlobalSetting::setValue('backup_keep_default', (string) (int) $data['backup_keep_default']);
        }
        if (! empty($data['backup_default_interval_hours'])) {
            ModManagerGlobalSetting::setValue('backup_default_interval_hours', (string) (int) $data['backup_default_interval_hours']);
        }
        // Empty selection = follow the panel app timezone (stored as null).
        ModManagerGlobalSetting::setValue('backup_timezone', ! empty($data['backup_timezone']) ? $data['backup_timezone'] : null);

        // API key: blank input keeps the existing key; explicit clear wipes it.
        if ($request->has('clear_curseforge_api_key')) {
            ModManagerGlobalSetting::setValue('curseforge_api_key', null);
        } elseif (! empty($data['curseforge_api_key'])) {
            ModManagerGlobalSetting::setValue('curseforge_api_key', trim($data['curseforge_api_key']));
        }

        return $this->adminFlash(['saved' => '1']);
    }

    /**
     * POST /admin/release { action: dismiss|check } (#12): hide the new
     * release notice until the next one, or ask GitHub now.
     */
    public function adminRelease(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $releases = app(\ServerSuite\Services\ReleaseCheckService::class);
        $action = (string) $request->input('action');
        if ($action === 'dismiss') {
            $releases->dismiss();
            $q = ['release_ok' => 'Hidden until the next release.'];
        } elseif ($action === 'check' && $releases->enabled()) {
            $releases->check();
            $q = ['release_ok' => $releases->notice() === null ? 'No newer release found.' : 'A newer release is out.'];
        } else {
            $q = ['release_error' => 'The release check is turned off.'];
        }

        return $this->adminFlash($q);
    }

    /**
     * POST /admin/import-egg — import an egg (from a PTDL JSON URL) into a nest
     * via the panel's own importer, so it becomes selectable as a loader.
     */
    public function adminImportEgg(Request $request)
    {
        if (! $request->user()?->root_admin) {
            abort(403);
        }

        $data = $request->validate([
            'nest_id' => ['required', 'integer'],
            'egg_url' => ['required', 'string', 'max:2048'],
        ]);

        try {
            $result = app(\ServerSuite\Services\EggManagerService::class)
                ->importFromUrl(trim($data['egg_url']), (int) $data['nest_id']);

            return $this->adminFlash(['egg_imported' => (string) ($result['name'] ?? 'egg')]);
        } catch (\Throwable $e) {
            return $this->adminFlash(['egg_error' => $e->getMessage()]);
        }
    }

    /**
     * GET /admin/egg-catalog — the remote pelican-eggs index as a nest > egg
     * tree (JSON), for the admin Egg Manager's cascading dropdowns. Cached in
     * the backend; pass ?refresh=1 to force a re-fetch.
     */
    public function adminEggCatalog(Request $request): JsonResponse
    {
        if (! $request->user()?->root_admin) {
            abort(403);
        }

        $svc = app(\ServerSuite\Services\EggCatalogService::class);
        $tree = $request->boolean('refresh') ? $svc->refresh() : $svc->index();

        return response()->json($tree);
    }

    /**
     * POST /admin/import-catalog — import a catalog egg (identified by its nest
     * category name + raw JSON URL) into that nest, creating the nest if it
     * doesn't exist. The URL is validated against the catalog by the service.
     */
    public function adminImportCatalogEgg(Request $request)
    {
        if (! $request->user()?->root_admin) {
            abort(403);
        }

        $data = $request->validate([
            'nest_name' => ['required', 'string', 'max:191'],
            'egg_url'   => ['required', 'string', 'max:2048'],
        ]);

        try {
            $result = app(\ServerSuite\Services\EggManagerService::class)
                ->importCatalogEgg(trim($data['nest_name']), trim($data['egg_url']));

            return $this->adminFlash(['egg_imported' => (string) ($result['name'] ?? 'egg')]);
        } catch (\Throwable $e) {
            return $this->adminFlash(['egg_error' => $e->getMessage()]);
        }
    }

    /**
     * POST /admin/update-eggs — refresh every installed egg in place from its
     * own stored update URL. Optionally scoped to one nest.
     */
    public function adminUpdateAllEggs(Request $request)
    {
        if (! $request->user()?->root_admin) {
            abort(403);
        }

        $data = $request->validate([
            'nest_id' => ['nullable', 'integer'],
        ]);

        try {
            $nestId = isset($data['nest_id']) && $data['nest_id'] !== null ? (int) $data['nest_id'] : null;
            $result = app(\ServerSuite\Services\EggManagerService::class)->updateAllEggs($nestId);
            $c = $result['counts'];

            return $this->adminFlash(['eggs_updated' => $c['updated'], 'eggs_skipped' => $c['skipped'], 'eggs_failed' => $c['failed']]);
        } catch (\Throwable $e) {
            return $this->adminFlash(['egg_error' => $e->getMessage()]);
        }
    }



    /**
     * GET /{server}/reconcile
     * Scan the mod directory vs the manifest: untracked files (with
     * identification candidates) and missing manifest entries.
     */
    public function reconcile(Request $request, string $server, ReconciliationService $recon): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        try {
            return response()->json(['data' => $recon->reconcile($srv)]);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /{server}/reconcile/adopt   { filename, source, mod_id?, version_id? }
     * Track an untracked file in the manifest (user-confirmed).
     */
    public function reconcileAdopt(Request $request, string $server, ReconciliationService $recon): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'filename'   => ['required', 'string', 'max:255'],
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar,manual'],
            'mod_id'     => ['nullable', 'string', 'max:128'],
            'version_id' => ['nullable', 'string', 'max:128'],
        ]);

        try {
            $row = $recon->adopt(
                $srv,
                $data['filename'],
                $data['source'],
                $data['mod_id'] ?? null,
                $data['version_id'] ?? null,
                (int) ($request->user()->id ?? 0),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'mod-adopted', ['file' => $data['filename'], 'source' => $data['source']]);
        return response()->json(['data' => ['adopted' => true, 'id' => $row->id]]);
    }

    /**
     * POST /{server}/reconcile/forget   { id }
     * Drop a manifest row whose file is gone from disk (manifest-only).
     */
    public function reconcileForget(Request $request, string $server, ReconciliationService $recon): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate(['id' => ['required', 'integer']]);

        $mod = ServerMod::where('id', $data['id'])->where('server_uuid', $srv->uuid)->firstOrFail();
        $recon->forget($mod);

        $this->audit($srv, 'mod-forgotten', ['mod' => $mod->mod_name]);
        return response()->json(['data' => ['forgotten' => true]]);
    }

    /**
     * GET /{server}/repositories
     * Lists each repository and whether it's currently usable, so the UI can
     * mark CurseForge (needs key) / Hangar (stub) appropriately instead of
     * silently returning empty results.
     */
    public function repositories(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $out = [];
        foreach (['modrinth', 'curseforge', 'hangar'] as $key) {
            try {
                $driver = $this->repositories->driver($key);
                $out[] = [
                    'key'       => $key,
                    'label'     => $driver->label(),
                    'available' => $driver->isAvailable(),
                ];
            } catch (\Throwable) {
                $out[] = ['key' => $key, 'label' => ucfirst($key), 'available' => false];
            }
        }

        return response()->json(['data' => $out]);
    }

    /**
     * POST /{server}/search   { query, source?, limit? }
     */
    public function search(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'query'           => ['nullable', 'string'],   // empty = browse all (paginated)
            'source'          => ['nullable', 'string', 'in:modrinth,curseforge,hangar'],
            'sort'            => ['nullable', 'string', 'in:relevance,downloads,follows,newest,updated'],
            'offset'          => ['nullable', 'integer', 'min:0'],
            'limit'           => ['nullable', 'integer', 'min:1', 'max:50'],
            'loader'          => ['nullable', 'string'],
            'version'         => ['nullable', 'string'],
            'compatible_only' => ['nullable', 'boolean'],
        ]);

        $ctx = $this->mods->searchWithContext(
            $data['query'] ?? '',
            $srv,
            $data['limit'] ?? 20,
            $data['loader'] ?? null,
            $data['version'] ?? null,
            $data['source'] ?? null,   // restrict to the chosen provider
            $data['sort'] ?? null,
            (int) ($data['offset'] ?? 0),
            (bool) ($data['compatible_only'] ?? false),
        );

        return response()->json([
            'data'        => array_map(fn ($r) => $r->toArray(), $ctx['data']),
            'next_offset' => $ctx['next_offset'],
            'has_more'    => $ctx['has_more'],
        ]);
    }

    /**
     * POST /{server}/versions   { source, mod_id }
     */
    public function versions(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'source' => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id' => ['required', 'string', 'max:128'],
        ]);

        $versions = $this->mods->versionsFor($data['source'], $data['mod_id'], $srv);

        return response()->json(['data' => array_map(fn ($v) => $v->toArray(), $versions)]);
    }

    /**
     * GET /{server}/server-versions
     * Versions module (Phase 1, read-only): what the server runs now + the
     * versions its loader publishes upstream. The backup-gated switch (Phase 2)
     * is a separate, write endpoint built against the server's egg.
     */
    public function serverVersions(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $svc       = app(ServerVersionService::class);
        $current   = $svc->current($srv);
        $available = $svc->available($current['loader']);

        return response()->json(['data' => [
            'current'     => $current,
            'available'   => $available,
            'backups_off' => $this->backupsOff($srv),
        ]]);
    }

    /**
     * GET /{server}/auto-upgrade
     * Automatic upgrades (#111): this server's settings and what last happened.
     */
    public function autoUpgradeState(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\AutoUpgradeService::class)->overview($srv)]);
    }

    /**
     * POST /{server}/auto-upgrade   { enabled, range, hour }
     * Saving with it on also clears a pause after a failed upgrade.
     */
    public function autoUpgradeSave(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'range'   => ['required', 'string', 'in:line,latest'],
            'hour'    => ['required', 'integer', 'min:0', 'max:23'],
        ]);

        $upgrades = app(\ServerSuite\Services\AutoUpgradeService::class);
        try {
            $upgrades->configure($srv, (bool) $data['enabled'], $data['range'], (int) $data['hour']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'auto-upgrade-settings', $data);

        return response()->json(['data' => $upgrades->overview($srv)]);
    }

    /**
     * GET /{server}/server-status
     * Live power + install state, for polling during switch / nuclear flows.
     */
    public function serverStatus(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(PanelServerOps::class)->runtimeState($server)]);
    }

    /**
     * POST /{server}/version-nuclear   { mode, confirm_name }
     * Destructive reset: wipe files (mode) then reinstall. Gated by typing the
     * server name. Returns {status: stopping} until the server is offline, then
     * {status: reinstalling} once the wipe + reinstall has fired.
     */
    public function serverVersionNuclear(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'mode'         => ['required', 'string', 'in:keep_plugins,clean_slate'],
            'confirm_name' => ['required', 'string'],
        ]);

        // Hard gate: the typed name must match this server's name exactly.
        if (trim($data['confirm_name']) !== $srv->name) {
            return response()->json(['error' => 'The server name you typed does not match.'], 422);
        }

        try {
            $result = app(\ServerSuite\Services\VersionSwitchService::class)->nuclear($srv, $data['mode']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? null) !== 'stopping') {   // the tab calls again while the server stops: log the start once
            $this->audit($srv, 'reset', ['mode' => $data['mode']]);
        }
        return response()->json(['data' => $result]);
    }

    /**
     * GET /{server}/loaders
     * Current loader/version plus the loaders installable on this panel (one per
     * Minecraft server-loader egg).
     */
    /**
     * GET /{server}/loader-versions?loader=forge
     * Minecraft versions a loader can switch to, newest first, for the loader
     * switch's version picker. Empty with supported=false for loaders that
     * have no list (the picker falls back to typing a version).
     */
    public function loaderVersions(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate(['loader' => ['required', 'string', 'max:32', 'regex:/^[a-z]+$/']]);

        return response()->json(['data' => app(ServerVersionService::class)->available($data['loader'])]);
    }

    public function loaders(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $current = app(ServerVersionService::class)->current($srv);

        return response()->json(['data' => [
            'current'     => ['loader' => $current['loader'] ?? null, 'version' => $current['game_version'] ?? null],
            'installable' => app(\ServerSuite\Services\EggManagerService::class)->minecraftLoaders(),
        ]]);
    }

    /**
     * GET /{server}/game: which game the server runs and the Server Suite
     * features it gets (#77). The sidebar uses it to hide tabs.
     */
    public function game(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $game = app(\ServerSuite\Services\GameDetectionService::class)->for($srv);
        // Bedrock Dedicated Server: Add-ons instead of Mods, and none of the Java-only tabs (#93).
        $features = \ServerSuite\Support\GameGuard::features($srv);

        return response()->json(['data' => [
            'key' => $game->key, 'name' => $game->name, 'features' => $features, 'mods_label' => $game->modsLabel,
            'config_roots' => $game->configRoots,
            // The panel sets installed_at when an install ends, so null means the first one is running.
            'first_install' => $srv->installed_at === null,
        ]]);
    }

    /**
     * POST /{server}/loader-switch   { egg_id, version?, confirm_name }
     * Destructive: a loader change is always a clean-slate reset. Gated by typing
     * the server name. Returns {status: stopping} until offline, then reinstalling.
     */
    public function loaderSwitch(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'egg_id'       => ['required', 'integer'],
            // Written into the egg's version variable: a version string, nothing else.
            'version'      => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9._+-]*$/'],
            'confirm_name' => ['required', 'string'],
        ]);

        // Only the loader eggs the Versions tab offers (#36): changing a
        // server's egg is otherwise an admin-only action in Pterodactyl.
        $loaders = app(\ServerSuite\Services\EggManagerService::class)->minecraftLoaders();
        $target = collect($loaders)->firstWhere('egg_id', (int) $data['egg_id']);
        if ($target === null) {
            return response()->json(['error' => 'That egg is not one of the loaders this panel offers.'], 422);
        }

        if (trim($data['confirm_name']) !== $srv->name) {
            return response()->json(['error' => 'The server name you typed does not match.'], 422);
        }

        try {
            // Forge/NeoForge: pin the loader build for the chosen version, as
            // the Versions tab does (the stock eggs pick it by prefix).
            $mc = $data['version'] ?? (app(ServerVersionService::class)->current($srv)['game_version'] ?? null);
            $pin = $mc ? app(ServerVersionService::class)->loaderPin($target['loader'] ?? null, $mc) : null;

            $result = app(\ServerSuite\Services\LoaderSwitchService::class)->switchLoader(
                $srv,
                (int) $data['egg_id'],
                $data['version'] ?? null,
                ! $this->backupsOff($srv),   // backup-gated, unless the server has no backup allocation
                $pin ? [$pin['var'] => $pin['value']] : [],
            );
        } catch (\Throwable $e) {
            app(\ServerSuite\Services\Notifier::class)->notify($srv->uuid, 'switch', 'Loader switch failed', $e->getMessage(), 'error');

            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? null) !== 'stopping') {   // the tab calls again while the server stops: log the start once
            app(\ServerSuite\Services\Notifier::class)->notify($srv->uuid, 'switch', 'Loader switch started', 'The server is reinstalling. It starts again when the install finishes.');
            $this->audit($srv, 'loader-switch', ['egg' => (int) $data['egg_id'], 'version' => $data['version'] ?? null]);
        }
        return response()->json(['data' => $result]);
    }

    /**
     * POST /{server}/version-preflight   { version, build? }
     * Read-only: validates a target version and returns direction, resolved
     * build, image change, warnings and blockers for the switch Preview.
     */
    public function serverVersionPreflight(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'build'   => ['nullable', 'integer'],
        ]);

        $pf = app(ServerVersionService::class)->preflight($srv, $data['version'], $data['build'] ?? null);

        return response()->json(['data' => $pf]);
    }

    /**
     * POST /{server}/version-apply   { version, build?, delete_plugins? }
     * Destructive: pins the version, sets the image, (optionally) clears plugin
     * jars, and reinstalls. Backup-gated server-side — a recent completed backup
     * must exist. The install then runs async; the client polls status.
     */
    public function serverVersionApply(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'version'        => ['required', 'string', 'max:32'],
            'build'          => ['nullable', 'integer'],
            'delete_plugins' => ['nullable', 'boolean'],
            'wipe_mode'      => ['nullable', 'string', 'in:none,reset_world,clean_slate'],
        ]);

        try {
            $result = app(\ServerSuite\Services\VersionSwitchService::class)->apply(
                $srv,
                $data['version'],
                $data['build'] ?? null,
                ! $this->backupsOff($srv),              // requireBackup, unless the server has no backup allocation
                (bool) ($data['delete_plugins'] ?? false),   // deleting jars is opt-in; the tab always sends it
                $data['wipe_mode'] ?? 'none',
            );
        } catch (\Throwable $e) {
            app(\ServerSuite\Services\Notifier::class)->notify($srv->uuid, 'switch', 'Switch to Minecraft ' . $data['version'] . ' failed', $e->getMessage(), 'error');

            return response()->json(['error' => $e->getMessage()], 422);
        }

        if (($result['status'] ?? null) !== 'stopping') {   // the tab calls again while the server stops: log the start once
            app(\ServerSuite\Services\Notifier::class)->notify($srv->uuid, 'switch', 'Switch to Minecraft ' . $data['version'] . ' started', 'The server is reinstalling. It starts again when the install finishes.');
            $this->audit($srv, 'version-switch', ['target' => $data['version'], 'wipe' => $data['wipe_mode'] ?? 'none']);
        }
        return response()->json(['data' => $result]);
    }

    /**
     * POST /{server}/plan   { source, mod_id, version_id }
     * Read-only: returns the dependency-resolved install plan for confirmation.
     */
    public function plan(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'     => ['required', 'string', 'max:128'],
            'version_id' => ['required', 'string', 'max:128'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $srv);

        return response()->json(['data' => $plan->toArray()]);
    }

    /**
     * POST /{server}/install-batch { items: [{source, mod_id, version_id}], clearance }
     * The install queue: every queued mod (and its dependencies, each once)
     * in one queued batch behind one pre-change backup.
     */
    public function installBatch(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'items'              => ['required', 'array', 'min:1', 'max:50'],
            'items.*.source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'items.*.mod_id'     => ['required', 'string', 'max:128'],
            'items.*.version_id' => ['required', 'string', 'max:128'],
        ]);

        $plan = $this->mods->planBatch($data['items'], $srv);

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }
        try {
            $result = $this->mods->executePlan($plan, $srv, (int) $request->user()->id, true);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $count = count($data['items']);
        $this->audit($srv, 'mods-installed', ['count' => $count, 'mods' => implode(', ', array_column($data['items'], 'mod_id')), 'backup' => $clearance['backup']]);
        $this->recordChange($srv, "install {$count} mods", $clearance);

        return response()->json(['data' => $result + ['plan' => $plan->toArray()['summary']]]);
    }

    /**
     * POST /{server}/install   { source, mod_id, version_id, queue? }
     */
    public function install(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'     => ['required', 'string', 'max:128'],
            'version_id' => ['required', 'string', 'max:128'],
            'queue'      => ['nullable', 'boolean'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $srv);

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }
        try {
            $result = $this->mods->executePlan($plan, $srv, (int) $request->user()->id, (bool) ($data['queue'] ?? false));
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'mod-installed', ['source' => $data['source'], 'mod' => $data['mod_id'], 'version' => $data['version_id'], 'backup' => $clearance['backup']]);
        $this->recordChange($srv, 'install ' . $data['mod_id'], $clearance);
        return response()->json(['data' => $result]);
    }

    /**
     * GET /{server}/tasks/{batch}
     */
    public function tasks(Request $request, string $server, string $batch): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $tasks = ModInstallTask::forBatch($batch)
            ->where('server_uuid', $srv->uuid)
            ->get(['mod_name', 'kind', 'status', 'error', 'version_number']);

        $complete = $tasks->every(fn ($t) => in_array($t->status, ['success', 'failed', 'skipped'], true));

        return response()->json([
            'data' => $tasks,
            'meta' => [
                'complete'  => $complete,
                'total'     => $tasks->count(),
                'succeeded' => $tasks->where('status', 'success')->count(),
                'failed'    => $tasks->where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * GET /{server}/installed
     */
    public function installed(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $mods = $this->mods->installed($srv->uuid)->map(fn (ServerMod $m) => [
            'id'                => $m->id,
            'source'            => $m->source,
            'source_id'         => $m->source_id,
            'mod_name'          => $m->mod_name,
            'icon_url'          => $m->icon_url,
            'page_url'          => $m->page_url,
            'installed_version' => $m->installed_version,
            'target_filename'   => $m->target_filename,
            'has_update'        => $m->pendingUpdate !== null,
            'disabled'          => $m->disabled_at !== null,
            'available_version' => $m->pendingUpdate->available_version ?? null,
            'from_modpack'      => $m->modpack_id !== null,
        ]);

        return response()->json(['data' => $mods]);
    }

    /**
     * POST /{server}/mods/{serverMod}/update
     */
    public function update(Request $request, string $server, int $serverMod): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $mod = ServerMod::where('id', $serverMod)->where('server_uuid', $srv->uuid)->firstOrFail();

        if ($mod->source === 'manual') {
            return response()->json(['error' => 'Manually tracked files have no repository to update from.'], 422);
        }

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }
        try {
            $updated = $this->mods->update($mod, $srv);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'mod-updated', ['mod' => $mod->mod_name, 'version' => $updated->installed_version, 'backup' => $clearance['backup']]);
        $this->recordChange($srv, 'update ' . $mod->mod_name, $clearance);
        return response()->json(['data' => [
            'mod_name'          => $updated->mod_name,
            'installed_version' => $updated->installed_version,
        ]]);
    }

    /**
     * POST /{server}/mods/remove-batch { ids, probe?, force?, orphans?, clearance }
     * Remove several mods at once, behind one pre-change backup. A probe
     * (never deletes) reports mods outside the selection that still need one
     * of them, and dependencies nothing will need afterwards; `orphans` picks
     * which of those go too. Dependents outside the selection need `force`.
     */
    public function removeBatch(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'ids'       => ['required', 'array', 'min:1', 'max:200'],
            'ids.*'     => ['integer'],
            'orphans'   => ['nullable', 'array', 'max:200'],
            'orphans.*' => ['integer'],
            'probe'     => ['nullable', 'boolean'],
            'force'     => ['nullable', 'boolean'],
        ]);

        $mods = ServerMod::forServer($srv->uuid)->whereIn('id', array_unique($data['ids']))->get()->all();
        if (count($mods) !== count(array_unique($data['ids']))) {
            return response()->json(['error' => 'Some of those mods are not installed on this server any more. Refresh and try again.'], 404);
        }

        $dependents = $this->mods->dependentsOfSet($mods);
        $orphans = $this->mods->orphanedBySet($mods);

        if (! empty($data['probe'])) {
            return response()->json(['data' => [
                'dependents' => $dependents,
                'orphans'    => array_map(fn (ServerMod $m) => ['id' => $m->id, 'mod_name' => $m->mod_name, 'installed_version' => $m->installed_version], $orphans),
            ]]);
        }
        if ($dependents !== [] && empty($data['force'])) {
            return response()->json(['needs_confirmation' => true, 'dependents' => $dependents,
                'message' => 'Other installed mods still need some of these.'], 409);
        }

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }

        // Only orphans the probe would have offered.
        $wanted = array_flip(array_map('intval', $data['orphans'] ?? []));
        $targets = array_merge($mods, array_values(array_filter($orphans, fn (ServerMod $m) => isset($wanted[$m->id]))));

        $removed = $failed = [];
        foreach ($targets as $mod) {
            try {
                $this->mods->remove($mod, $srv, true);   // the set was checked above
                $removed[] = $mod;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $mod->id, 'mod_name' => $mod->mod_name, 'error' => $e->getMessage()];
            }
        }

        if ($removed !== []) {
            $names = array_map(fn (ServerMod $m) => $m->mod_name, $removed);
            $this->audit($srv, 'mods-removed', ['count' => count($removed), 'mods' => implode(', ', $names), 'backup' => $clearance['backup']]);
            $this->recordChange($srv, 'remove ' . count($removed) . ' mods', $clearance);
        }

        return response()->json(['data' => [
            'removed' => array_map(fn (ServerMod $m) => $m->id, $removed),
            'failed'  => $failed,
        ]], $failed === [] ? 200 : 207);
    }

    /**
     * DELETE /{server}/mods/{serverMod}
     */
    public function destroy(Request $request, string $server, int $serverMod): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $mod = ServerMod::where('id', $serverMod)->where('server_uuid', $srv->uuid)->firstOrFail();

        $force = (bool) $request->input('force', false);
        $probe = (bool) $request->input('probe', false);

        // Pre-check dependents so the UI can warn before forcing.
        if (! $force) {
            $dependents = $this->mods->dependentsOf($mod);

            // A probe never deletes — it only reports whether dependents exist
            // and which dependencies would be orphaned, so the removal modal can
            // present both before the user decides.
            if ($probe) {
                return response()->json([
                    'probe' => true,
                    'dependents' => array_map(fn (ServerMod $m) => [
                        'id' => $m->id, 'mod_name' => $m->mod_name,
                    ], $dependents),
                    'orphans' => array_map(fn (ServerMod $m) => [
                        'id' => $m->id, 'mod_name' => $m->mod_name, 'installed_version' => $m->installed_version,
                    ], $this->mods->orphanedBy($mod)),
                ], count($dependents) > 0 ? 409 : 200);
            }

            if (count($dependents) > 0) {
                return response()->json([
                    'needs_confirmation' => true,
                    'dependents' => array_map(fn (ServerMod $m) => [
                        'id' => $m->id, 'mod_name' => $m->mod_name,
                    ], $dependents),
                    'message' => "{$mod->mod_name} is required by other installed mods.",
                ], 409);
            }
        }

        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }
        try {
            $this->mods->remove($mod, $srv, $force);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $this->audit($srv, 'mod-removed', ['mod' => $mod->mod_name, 'backup' => $clearance['backup']]);
        $this->recordChange($srv, 'remove ' . $mod->mod_name, $clearance);
        return response()->json(['data' => ['removed' => true]]);
    }

    // -------------------------------------------------------------------------

    /**
     * GET /{server}/backups
     * Backups list plus this server's effective limit and per-server settings.
     */
    public function backups(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $ops      = app(PanelServerOps::class);
        $resolver = app(BackupRetentionResolver::class);
        $setting  = ModManagerSetting::query()->where('server_uuid', $server)->first();

        $limit = $ops->serverBackupLimit($server);

        $presets = array_values((array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]));

        return response()->json(['data' => [
            'backups'        => array_map(fn ($b) => $b->toArray(), $ops->listBackups($server)),
            'limit'          => $limit,
            // Retention equals the server's backup limit (panel-admin controlled);
            // the resolver only acts as a fallback when no limit is set.
            'effective_keep' => $limit ?? $resolver->resolve($server),
            'settings'       => [
                'enabled' => (bool) ($setting->backup_rotation_enabled ?? false),
            ],
            'schedule'       => [
                'interval_hours'         => $setting?->backup_interval_hours,
                'default_interval_hours' => (int) config('serversuite.backups.default_interval_hours', 12),
                'presets'                => $presets,
                // Fixed run hour (0-23) in the backup timezone; null = no fixed time.
                'hour'                   => $setting?->backup_schedule_hour,
                'timezone'               => \ServerSuite\Support\BackupSchedule::timezone(),
                'last_run_at'            => optional($setting?->backup_last_run_at)->toIso8601String(),
                'next_run_at'            => ($setting?->backup_rotation_enabled)
                    ? optional(\ServerSuite\Support\BackupSchedule::nextRun(
                        (int) ($setting->backup_interval_hours ?: config('serversuite.backups.default_interval_hours', 12)),
                        $setting->backup_schedule_hour,
                        $setting->backup_last_run_at,
                        \ServerSuite\Support\BackupSchedule::now(),
                    ))->toIso8601String()
                    : null,
            ],
        ]]);
    }

    /**
     * POST /{server}/backups/settings   { enabled, interval_hours, hour }
     * Per-server opt-in to scheduled rotation. Retention is fixed to the
     * server's backup limit and is not editable here.
     */
    public function backupsSaveSettings(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $presets = array_values((array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]));

        $data = $request->validate([
            'enabled'        => ['nullable', 'boolean'],
            // null = use the global default; otherwise must be a bounded preset
            // so a server admin can never pick an unsafe (throttle-tripping) cadence.
            'interval_hours' => ['nullable', 'integer', \Illuminate\Validation\Rule::in($presets)],
            // null = no fixed time (runs once the interval has passed)
            'hour'           => ['nullable', 'integer', 'between:0,23'],
        ]);

        // Retention is governed by the server's backup limit (panel admins only).
        // Server admins control opt-in to scheduled rotation and their cadence.
        $setting = ModManagerSetting::forServer($server);
        $setting->backup_rotation_enabled = (bool) ($data['enabled'] ?? false);
        $setting->backup_interval_hours = $data['interval_hours'] ?? null;
        $setting->backup_schedule_hour = $data['hour'] ?? null;
        $setting->save();

        $this->audit($srv, 'backup-schedule-saved', []);
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST /{server}/backups/rotate
     * Run one rotation cycle now (create + evict-oldest per policy).
     */
    public function backupsRotate(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $result = app(BackupRotationService::class)->rotate($server);

        $this->audit($srv, 'backup-rotated', ['result' => $result->toArray()['status'] ?? null]);
        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * POST /{server}/backups   { name? }
     * Manual user-triggered backup with an optional custom name.
     */
    public function backupsCreate(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            // #130: at the limit this deletes the oldest backup, so only for those who may delete.
            $result = app(BackupRotationService::class)->backupNow($server, $data['name'] ?? null, PanelPermissions::holds($request->user(), $srv, ['backup.delete']));
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'backup-created', ['name' => $data['name'] ?? null]);
        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * POST /{server}/backups/{backup}/rename   { name }
     */
    public function backupsRename(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
        ]);

        try {
            app(PanelServerOps::class)->renameBackup($server, $backup, $data['name']);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'backup-renamed', ['backup' => $backup, 'name' => $data['name']]);
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST /{server}/backups/{backup}/lock   { locked }
     * Lock/unlock a backup (locked backups are protected from eviction).
     */
    public function backupsLock(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate(['locked' => ['required', 'boolean']]);

        try {
            app(PanelServerOps::class)->setBackupLocked($server, $backup, $data['locked']);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, $data['locked'] ? 'backup-locked' : 'backup-unlocked', ['backup' => $backup]);
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * DELETE /{server}/backups/{backup}
     */
    public function backupsDelete(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        try {
            app(PanelServerOps::class)->deleteBackup($server, $backup);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        app(\ServerSuite\Services\RestoreSyncService::class)->forget($backup);

        $this->audit($srv, 'backup-deleted', ['backup' => $backup]);
        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * GET /{server}/backups/{backup}/download
     * Returns a short-lived signed URL the browser can open.
     */
    public function backupsDownload(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        try {
            $url = app(PanelServerOps::class)->downloadUrl($server, $backup, $request->user());
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->audit($srv, 'backup-downloaded', ['backup' => $backup]);
        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * POST /{server}/backups/{backup}/restore   { truncate? }
     * Destructive: restores the backup over the server's files.
     */
    public function backupsRestore(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $data = $request->validate([
            'truncate' => ['nullable', 'boolean'],
        ]);

        try {
            app(PanelServerOps::class)->restoreBackup(
                $server,
                $backup,
                (bool) ($data['truncate'] ?? false),
                $request->user(),
            );
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        // When Wings finishes, the mod list is brought back in line (#60).
        app(\ServerSuite\Services\RestoreSyncService::class)->noteRestore($srv->uuid, $backup, (bool) ($data['truncate'] ?? false));

        // Re-align the server's version to the one recorded when Server Suite
        // took the backup (not its name, which users can edit: #119), so the
        // restored world matches the server's version settings.
        // Best-effort — never allowed to fail the restore.
        $aligned = null;
        try {
            $aligned = app(\ServerSuite\Services\VersionSwitchService::class)
                ->alignToBackupVersion($srv, $backup);
        } catch (\Throwable $e) {
            // alignment is a nicety, not a requirement
        }

        $this->audit($srv, 'backup-restored', ['backup' => $backup, 'truncate' => (bool) ($data['truncate'] ?? false)]);
        return response()->json(['data' => ['ok' => true, 'aligned' => $aligned]]);
    }

    /** GET /{server}/restore-sync: what the last backup restore changed in the mod list (#60), or null */
    public function restoreSync(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\RestoreSyncService::class)->lastResult($srv->uuid)]);
    }

    /** POST /{server}/restore-sync/dismiss: hide the banner */
    public function restoreSyncDismiss(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        app(\ServerSuite\Services\RestoreSyncService::class)->dismiss($srv->uuid);

        return response()->json(['data' => ['ok' => true]]);
    }

    // -------------------------------------------------------------------------
    // Backup before mod changes (#24)
    // -------------------------------------------------------------------------

    /** GET /{server}/prechange: the server's setting (always | ask | never) */
    public function prechangeMode(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => ['mode' => \ServerSuite\Services\PreChangeBackup::mode($srv->uuid)]]);
    }

    /** POST /{server}/prechange/mode { mode } */
    public function prechangeSetMode(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $mode = (string) $request->validate(['mode' => ['required', 'string', 'in:' . implode(',', \ServerSuite\Services\PreChangeBackup::MODES)]])['mode'];
        ModManagerSetting::forServer($srv->uuid)->forceFill(['pre_change_backup' => $mode])->save();
        $this->audit($srv, 'pre-change-backup-set', ['mode' => $mode]);

        return response()->json(['data' => ['mode' => $mode]]);
    }

    /**
     * POST /{server}/prechange { label, take? }: decide on the backup before
     * a change and hand back the clearance token the change needs.
     */
    public function prechange(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        if (! PanelPermissions::holds($request->user(), $srv, ['mods.install']) && ! PanelPermissions::holds($request->user(), $srv, ['mods.update'])
            && ! PanelPermissions::holds($request->user(), $srv, ['mods.remove'])) {
            abort(403, 'You do not have permission to change mods on this server.');
        }
        $data = $request->validate(['label' => ['required', 'string', 'max:150'], 'take' => ['nullable', 'boolean']]);
        $r = app(\ServerSuite\Services\PreChangeBackup::class)->clear($srv->uuid, $data['label'], array_key_exists('take', $data) && $data['take'] !== null ? (bool) $data['take'] : null,
            PanelPermissions::holds($request->user(), $srv, ['backup.delete']));   // #130

        return response()->json(['data' => $r], $r['status'] === 'failed' ? 422 : 200);
    }

    /** GET /{server}/prechange/{token}: waiting | ready | failed | unknown */
    public function prechangeState(Request $request, string $server, string $token): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => ['state' => app(\ServerSuite\Services\PreChangeBackup::class)->clearanceState($srv->uuid, $token)]]);
    }

    /**
     * The panel gives this server no backups (backup_limit 0), so a switch goes
     * ahead without one; the confirm dialog says so. An unknown limit counts as
     * allowed, so the backup is still required.
     */
    private function backupsOff(Server $srv): bool
    {
        try {
            $limit = app(PanelServerOps::class)->serverBackupLimit($srv->uuid);
        } catch (\Throwable) {
            return false;
        }

        return $limit !== null && $limit <= 0;
    }

    /**
     * The clearance a mod change needs when the server backs up first: the
     * redeemed clearance, or the 409 to send back.
     *
     * @return array{ok: bool, backup: ?string, message: ?string}|JsonResponse
     */
    protected function requireClearance(Request $request, Server $srv): array|JsonResponse
    {
        $c = app(\ServerSuite\Services\PreChangeBackup::class)->redeem($srv->uuid, $request->input('clearance'));

        return $c['ok'] ? $c : response()->json(['error' => $c['message'], 'needs_backup' => true], 409);
    }

    // -------------------------------------------------------------------------
    // Errors tab (#87; Pelican #41/#91)
    // -------------------------------------------------------------------------

    /**
     * GET /{server}/errors?warnings=1&refresh=1: the log's errors and
     * warnings grouped, with the plugin or mod each points at. Minecraft
     * reads logs/latest.log and lists crash reports; Rust and Valheim read
     * their own logs (the game profile's) and Rust gets the "Rust changed
     * under Oxide/Carbon" notice.
     */
    public function errors(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $games = app(\ServerSuite\Services\GameDetectionService::class);
        $minecraft = $games->for($srv)->isMinecraft();
        $svc = app(\ServerSuite\Services\ErrorLogService::class);
        if ($request->boolean('refresh')) {
            $svc->forget($srv->uuid);
        }
        $report = $svc->report($srv->uuid, $request->boolean('warnings'), $minecraft ? null : $games->logSources($srv));

        // Crash reports are Minecraft's. array_replace, not +: + keeps the report's own key.
        $report = $svc->withAck($srv->uuid, $minecraft ? $report : array_replace($report, ['crashes' => []]));

        return response()->json(['data' => ['minecraft' => $minecraft] + $report]);
    }

    /** POST /{server}/errors/acknowledge {undo?: bool}: hide what the Errors tab shows now, until it happens again */
    public function errorsAcknowledge(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $games = app(\ServerSuite\Services\GameDetectionService::class);
        $svc = app(\ServerSuite\Services\ErrorLogService::class);
        if ($request->boolean('undo')) {
            $svc->unacknowledge($srv->uuid);
        } else {
            $svc->acknowledge($srv->uuid, $games->for($srv)->isMinecraft() ? null : $games->logSources($srv));
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /** GET /{server}/errors/crash?name=crash-....txt : one crash report (Minecraft) */
    public function errorsCrash(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $name = (string) $request->validate(['name' => ['required', 'string', 'max:200']])['name'];

        $crash = app(\ServerSuite\Services\GameDetectionService::class)->for($srv)->isMinecraft()
            ? app(\ServerSuite\Services\ErrorLogService::class)->crash($srv->uuid, $name)
            : null;

        return $crash === null ? response()->json(['error' => 'No such crash report.'], 404) : response()->json(['data' => $crash]);
    }

    // -------------------------------------------------------------------------
    // Crash help (#25)
    // -------------------------------------------------------------------------

    /** Remembered so a crash after it can be traced to it. Never fails the change. */
    protected function recordChange(Server $srv, string $label, array $clearance): void
    {
        try {
            app(\ServerSuite\Services\CrashHelpService::class)->recordChange($srv->uuid, $label,
                ! empty($clearance['backup_uuid']) ? ['uuid' => $clearance['backup_uuid'], 'name' => $clearance['backup']] : null);
        } catch (\Throwable) {
        }
    }

    /** GET /{server}/crash: the crash after the last mod change, or null */
    public function crash(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        try {
            $crash = app(\ServerSuite\Services\CrashHelpService::class)->analyze($srv);
        } catch (\Throwable) {
            $crash = null;
        }

        return response()->json(['data' => $crash]);
    }

    /** POST /{server}/crash/dismiss */
    public function crashDismiss(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        app(\ServerSuite\Services\CrashHelpService::class)->dismiss($srv->uuid);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** POST /{server}/mods/{serverMod}/disable: rename the jar to .disabled (loaders skip it) */
    public function crashDisable(Request $request, string $server, int $serverMod): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $mod = ServerMod::where('id', $serverMod)->where('server_uuid', $srv->uuid)->whereNull('disabled_at')->firstOrFail();

        try {
            app(\ServerSuite\Services\CrashHelpService::class)->disable($mod);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'mod-disabled', ['mod' => $mod->mod_name]);

        return response()->json(['data' => ['ok' => true]]);
    }

    /** POST /{server}/mods/{serverMod}/enable */
    public function crashEnable(Request $request, string $server, int $serverMod): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $mod = ServerMod::where('id', $serverMod)->where('server_uuid', $srv->uuid)->whereNotNull('disabled_at')->firstOrFail();

        try {
            app(\ServerSuite\Services\CrashHelpService::class)->enable($mod);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'mod-enabled', ['mod' => $mod->mod_name]);

        return response()->json(['data' => ['ok' => true]]);
    }

    // -------------------------------------------------------------------------
    // Bedrock crossplay (#28)
    // -------------------------------------------------------------------------

    /** GET /{server}/crossplay */
    public function crossplay(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\CrossplayService::class)->status($srv)]);
    }

    /** POST /{server}/crossplay/install { clearance }: Geyser, Floodgate (+ ViaVersion on Paper) and their dependencies */
    public function crossplayInstall(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        try {
            $items = app(\ServerSuite\Services\CrossplayService::class)->planItems($srv);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }

        $done = [];
        $failed = [];
        foreach ($items as $it) {
            if (($it['status'] ?? '') !== 'install') {
                continue;
            }
            try {
                $this->mods->install($it['mod_id'], $it['source'], $it['version_id'], $srv, (int) $request->user()->id);
                $done[] = $it['mod_name'] ?? $it['mod_id'];
            } catch (\Throwable $e) {
                $failed[] = ($it['mod_name'] ?? $it['mod_id']) . ': ' . $e->getMessage();
            }
        }
        if ($done !== []) {
            $this->audit($srv, 'bedrock-setup', ['installed' => implode(', ', $done), 'backup' => $clearance['backup']]);
            $this->recordChange($srv, 'add Bedrock crossplay (Geyser + Floodgate)', $clearance);
        }

        return response()->json(['data' => ['installed' => $done, 'failed' => $failed]], $failed !== [] && $done === [] ? 422 : 200);
    }

    /** POST /{server}/crossplay/configure: Geyser's port, transport and signaling (needs a restart) */
    public function crossplayConfigure(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        try {
            $set = app(\ServerSuite\Services\CrossplayService::class)->configure($srv);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'bedrock-configured', ['transport' => $set['transport'], 'port' => $set['port']]);

        return response()->json(['data' => $set]);
    }

    // -------------------------------------------------------------------------
    // Velocity networks (#30)
    // -------------------------------------------------------------------------

    /** GET /{server}/network: on a proxy, the owner's servers; on a backend, its network */
    public function network(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $svc = app(\ServerSuite\Services\NetworkService::class);
        if (! \ServerSuite\Services\NetworkService::isProxy($srv)) {
            $m = $svc->membership($srv->uuid);

            return response()->json(['data' => ['proxy' => false, 'member' => $m ? ['proxy' => $m['proxy'], 'ready' => $m['ready']] : null]]);
        }

        return response()->json(['data' => ['proxy' => true, 'candidates' => $svc->candidates($srv)]]);
    }

    /** POST /{server}/network { backends: [{uuid, name}] in try order } */
    public function networkApply(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['backends' => ['present', 'array', 'max:50'], 'backends.*.uuid' => ['required', 'string'], 'backends.*.name' => ['required', 'string', 'max:32']]);
        $backends = [];
        foreach ($data['backends'] as $b) {
            $backends[(string) $b['uuid']] = (string) $b['name'];
        }

        try {
            $results = app(\ServerSuite\Services\NetworkService::class)->save($srv, $backends);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'network-applied', ['backends' => implode(', ', array_values($backends))]);

        return response()->json(['data' => ['results' => $results]]);
    }

    // -------------------------------------------------------------------------
    // Configs (#21)
    // -------------------------------------------------------------------------

    /** GET /{server}/configs?path=  (no path: the config roots that exist) */
    public function configs(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return $this->configAction(fn ($svc) => $svc->browse($srv->uuid, $request->query('path'), \ServerSuite\Services\ConfigFilesService::roots($srv), \ServerSuite\Services\ConfigFilesService::hidden($srv)));
    }

    /** GET /{server}/configs/file?path= : text, hash and the typed form */
    public function configsOpen(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:1024']])['path'];

        return $this->configAction(fn ($svc) => $svc->open($srv->uuid, $path, \ServerSuite\Services\ConfigFilesService::roots($srv), \ServerSuite\Services\ConfigFilesService::hidden($srv)));
    }

    /** POST /{server}/configs/render { path, hash, values } : the text with form values applied, not saved */
    public function configsRender(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['path' => ['required', 'string', 'max:1024'], 'hash' => ['required', 'string', 'size:40'], 'values' => ['present', 'array']]);

        return $this->configAction(fn ($svc) => ['content' => $svc->render($srv->uuid, $data['path'], $data['hash'], $data['values'], \ServerSuite\Services\ConfigFilesService::roots($srv), \ServerSuite\Services\ConfigFilesService::hidden($srv))]);
    }

    /** POST /{server}/configs/file { path, hash, values | content } */
    public function configsSave(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:1024'], 'hash' => ['required', 'string', 'size:40'],
            'values' => ['nullable', 'array', 'required_without:content'], 'content' => ['nullable', 'string', 'required_without:values'],
        ]);
        // A config edit is a mod change too: same "Backup before changes" (#24).
        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }

        $roots = \ServerSuite\Services\ConfigFilesService::roots($srv);
        $hidden = \ServerSuite\Services\ConfigFilesService::hidden($srv);

        return $this->configAction(function ($svc) use ($srv, $data, $clearance, $roots, $hidden) {
            $values = $data['values'] ?? null;
            $r = $svc->save($srv->uuid, $data['path'], $data['hash'], $values, $values === null ? ($data['content'] ?? '') : null, $roots, $hidden);
            if ($r['written']) {
                $changed = $r['changed'] === [] ? null : implode(', ', array_slice($r['changed'], 0, 20)) . (count($r['changed']) > 20 ? ', …' : '');
                $file = \ServerSuite\Services\ConfigFilesService::safe($data['path'], $roots, $hidden);
                $this->audit($srv, 'config-saved', ['file' => $file, 'changed' => $changed, 'backup' => $clearance['backup']]);
                $this->recordChange($srv, 'edit ' . ltrim($file, '/'), $clearance);
            }

            return $r + $svc->open($srv->uuid, $data['path'], $roots, $hidden);
        });
    }

    private function configAction(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn(app(\ServerSuite\Services\ConfigFilesService::class))]);
        } catch (\InvalidArgumentException | \ServerSuite\Support\Config\ConfigEditException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    // -------------------------------------------------------------------------
    // Properties (#20)
    // -------------------------------------------------------------------------

    /** GET /{server}/properties */
    public function properties(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\PropertiesService::class)->open($srv->uuid)]);
    }

    /** POST /{server}/properties { hash, values, after: none|restart|reset_world } */
    public function propertiesSave(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'hash' => ['required', 'string', 'size:40'], 'values' => ['present', 'array'],
            'after' => ['required', 'string', 'in:' . implode(',', array_keys(PanelPermissions::PROPERTIES_AFTER))],
        ]);
        if (! PanelPermissions::holds($request->user(), $srv, PanelPermissions::PROPERTIES_AFTER[$data['after']])) {
            abort(403, 'You do not have permission to do this on this server (needs ' . implode(', ', PanelPermissions::PROPERTIES_AFTER[$data['after']]) . ').');
        }

        try {
            $r = app(\ServerSuite\Services\PropertiesService::class)->save($srv->uuid, $data['hash'], $data['values'], $data['after']);
        } catch (\InvalidArgumentException | \ServerSuite\Support\Config\ConfigEditException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        if ($r['changed'] !== []) {
            $this->audit($srv, 'properties-saved', ['changed' => implode(', ', array_slice($r['changed'], 0, 20)), 'after' => $data['after']]);
        }

        return response()->json(['data' => $r]);
    }

    /** POST /{server}/properties/reset-world: called until it returns done */
    public function propertiesResetWorld(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        try {
            $r = app(\ServerSuite\Services\PropertiesService::class)->resetWorldStep($srv->uuid);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'World reset failed: ' . $e->getMessage()], 422);
        }
        if ($r['status'] === 'done') {
            $this->audit($srv, 'world-reset', []);
        }

        return response()->json(['data' => $r]);
    }

    // -------------------------------------------------------------------------
    // Modpacks (#23): Modrinth .mrpack install and update
    // -------------------------------------------------------------------------

    /** GET /{server}/modpacks: whether packs fit this server, its loader/version, the pack and any running install */
    public function modpacks(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $svc = app(\ServerSuite\Services\ModpackService::class);

        return response()->json(['data' => [
            'supported'  => $svc->supports($srv),
            'context'    => $svc->serverContext($srv),
            'status'     => $svc->status($srv),
            'can_switch' => $this->canSwitchForModpack($request, $srv),
            'sources'    => $svc->sources(),
        ]]);
    }

    /** A pack for another loader or version switches the server: the Versions tab's loader-switch permissions (#23 stage 2). */
    private function canSwitchForModpack(Request $request, Server $srv): bool
    {
        return \ServerSuite\Support\PanelPermissions::holds($request->user(), $srv, \ServerSuite\Support\PanelPermissions::required('loaderSwitch'));
    }

    /** GET /{server}/modpacks/search?query=&sort=&offset=&any_version= : packs with a build for this server's loader and version (or any, for a user who can switch) */
    public function modpacksSearch(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'query'  => ['nullable', 'string', 'max:100'],
            'sort'   => ['nullable', 'string', 'in:relevance,downloads,follows,newest,updated'],
            'offset' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'any_version' => ['nullable', 'boolean'],
            'source' => self::MODPACK_SOURCE,
        ]);
        $any = ! empty($data['any_version']) && $this->canSwitchForModpack($request, $srv);

        return $this->modpackCall($srv, fn ($svc) => $svc->search($srv, (string) ($data['query'] ?? ''), (int) ($data['offset'] ?? 0), 24, $data['sort'] ?? null, $any, $data['source'] ?? 'modrinth'));
    }

    /** GET /{server}/modpacks/{project}/versions : newest first, each marked compatible or not */
    public function modpackVersions(Request $request, string $server, string $project): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,64}$/', $project) === 1, 404);
        $data = $request->validate(['source' => self::MODPACK_SOURCE]);

        return $this->modpackCall($srv, fn ($svc) => $svc->versions($project, $srv, $data['source'] ?? 'modrinth'));
    }

    /** POST /{server}/modpacks/preview { project_id, version_id } : what installing would do */
    public function modpackPreview(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'project_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'version_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'source'     => self::MODPACK_SOURCE,
        ]);

        return $this->modpackCall($srv, function ($svc) use ($srv, $data, $request) {
            $p = $svc->preview($srv, $data['project_id'], $data['version_id'], $this->canSwitchForModpack($request, $srv), $data['source'] ?? 'modrinth');
            unset($p['plan']);   // stays on the server; start() rebuilds it

            return $p;
        });
    }

    /** POST /{server}/modpacks/install { project_id, version_id, confirmed, wipe_confirmed? } : stop, back up, switch if needed, install (queued) */
    public function modpackInstall(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'project_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'version_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'confirmed'  => ['required', 'accepted'],
            'wipe_confirmed' => ['nullable', 'boolean'],
            'source'     => self::MODPACK_SOURCE,
        ]);

        $res = $this->modpackCall($srv, function ($svc) use ($srv, $data, $request) {
            $pack = $svc->start($srv, $data['project_id'], $data['version_id'], (int) $request->user()->id,
                $this->canSwitchForModpack($request, $srv), ! empty($data['wipe_confirmed']), $data['source'] ?? 'modrinth');
            $this->audit($srv, 'modpack-started', ['pack' => $pack->target['name'] ?? null, 'version' => $pack->target['version_number'] ?? null]);

            return $svc->status($srv);
        });

        return $res->getStatusCode() === 200 ? $res->setStatusCode(202) : $res;
    }

    /** POST /{server}/modpacks/remove { confirmed, configs? } : stop, back up, delete the pack's files (queued) */
    public function modpackRemove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'confirmed' => ['required', 'accepted'],
            'configs'   => ['nullable', 'boolean'],
        ]);

        try {
            $svc = app(\ServerSuite\Services\ModpackService::class);
            $pack = $svc->remove($srv, (int) $request->user()->id, ! empty($data['configs']));
            $this->audit($srv, 'modpack-remove-started', ['pack' => $pack->name, 'version' => $pack->version_number]);

            return response()->json(['data' => $svc->status($srv)], 202);
        } catch (\ServerSuite\Exceptions\ModpackException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Validation for a modpack request's source (#23 stage 3). */
    private const MODPACK_SOURCE = ['nullable', 'string', 'in:modrinth,curseforge'];

    /** Modpack endpoints only work on a server a pack can run on; refusals come back as 422. */
    private function modpackCall(Server $srv, callable $fn): JsonResponse
    {
        $svc = app(\ServerSuite\Services\ModpackService::class);
        if (! $svc->supports($srv)) {
            return response()->json(['error' => 'Modpacks need a Fabric, Quilt, Forge or NeoForge server. Change the loader on the Versions tab first.'], 422);
        }

        try {
            return response()->json(['data' => $fn($svc)]);
        } catch (\ServerSuite\Exceptions\ModpackException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\ServerSuite\Exceptions\RepositoryException $e) {
            return response()->json(['error' => 'The modpack site did not answer: ' . $e->getMessage()], 502);
        }
    }

    // -------------------------------------------------------------------------
    // Players (#27)
    // -------------------------------------------------------------------------

    /** GET /{server}/players */
    public function players(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        $game = $this->steamGame($srv);
        if ($game !== null) {
            $labels = array_map(fn ($l) => $l['label'], \ServerSuite\Services\SteamPlayersService::LISTS[$game]);

            return response()->json(['data' => ['game' => $game, 'labels' => $labels]
                + app(\ServerSuite\Services\SteamPlayersService::class)->overview($srv, $game)]);
        }

        return response()->json(['data' => ['game' => 'minecraft'] + app(\ServerSuite\Services\PlayersService::class)->overview($srv)]);
    }

    /** Rust or Valheim (#85): SteamID lists instead of Minecraft's; null for Minecraft. */
    private function steamGame(Server $srv): ?string
    {
        $key = app(\ServerSuite\Services\GameDetectionService::class)->for($srv)->key;

        return \ServerSuite\Services\SteamPlayersService::supports($key) ? $key : null;
    }

    /** The list keys the server's game has. */
    private function playerLists(Server $srv): array
    {
        $game = $this->steamGame($srv);

        return array_keys($game === null ? \ServerSuite\Services\PlayersService::LISTS : \ServerSuite\Services\SteamPlayersService::LISTS[$game]);
    }

    /** POST /{server}/players { list, who, name?, reason? } (name: Rust only) */
    public function playersAdd(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['list' => ['required', 'string', 'in:' . implode(',', $this->playerLists($srv))],
            'who' => ['required', 'string', 'max:80'], 'name' => ['nullable', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:200']]);
        $game = $this->steamGame($srv);
        $fn = $game === null
            ? fn ($svc) => $svc->add($srv, $data['list'], $data['who'], (string) ($data['reason'] ?? ''))
            : fn () => app(\ServerSuite\Services\SteamPlayersService::class)->add($srv, $game, $data['list'], $data['who'], (string) ($data['name'] ?? ''), (string) ($data['reason'] ?? ''));

        return $this->playersAction($srv, $fn, 'players-added', ['list' => $data['list'], 'who' => trim($data['who'])]);
    }

    /** POST /{server}/players/remove { list, who } */
    public function playersRemove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['list' => ['required', 'string', 'in:' . implode(',', $this->playerLists($srv))],
            'who' => ['required', 'string', 'max:80']]);
        $game = $this->steamGame($srv);
        $fn = $game === null
            ? fn ($svc) => $svc->remove($srv, $data['list'], $data['who'])
            : fn () => app(\ServerSuite\Services\SteamPlayersService::class)->remove($srv, $game, $data['list'], $data['who']);

        return $this->playersAction($srv, $fn, 'players-removed', ['list' => $data['list'], 'who' => $data['who']]);
    }

    /** POST /{server}/players/whitelist { on } */
    public function playersWhitelist(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        if ($this->steamGame($srv) !== null) {
            return response()->json(['error' => 'This game has no whitelist switch.'], 422);
        }

        return $this->playersAction($srv, fn ($svc) => $svc->setWhitelist($srv, $on), $on ? 'whitelist-on' : 'whitelist-off', []);
    }

    private function playersAction(Server $srv, callable $fn, string $event, array $props): JsonResponse
    {
        try {
            $msg = $fn(app(\ServerSuite\Services\PlayersService::class));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, $event, $props);

        return response()->json(['data' => ['message' => $msg]]);
    }

    // -------------------------------------------------------------------------
    // Bedrock add-ons (#93; Pelican #97)
    // -------------------------------------------------------------------------

    /** GET /{server}/addons: the /addons folder (null: none yet) and the installed packs */
    public function addons(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $svc = app(\ServerSuite\Services\BedrockAddonService::class);

        return $this->addonAction(fn () => ['inbox' => $svc->inbox($srv->uuid)] + $svc->installed($srv->uuid));
    }

    /** POST /{server}/addons/folder: create /addons */
    public function addonsFolder(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);

        return $this->addonAction(function () use ($srv) {
            app(\ServerSuite\Services\BedrockAddonService::class)->createInbox($srv->uuid);
            $this->audit($srv, 'addons-folder-created');

            return ['message' => 'Created the addons folder. Upload .mcpack and .mcaddon files to it in the File Manager.'];
        });
    }

    /** POST /{server}/addons/install { file, clearance? }: an archive from /addons */
    public function addonsInstall(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $file = (string) $request->validate(['file' => ['required', 'string', 'max:255']])['file'];
        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }

        return $this->addonAction(function () use ($srv, $file, $clearance) {
            $r = app(\ServerSuite\Services\BedrockAddonService::class)->install($srv->uuid, $file);
            $this->audit($srv, 'addon-installed', ['addon' => implode(', ', [...$r['installed'], ...$r['updated']]), 'file' => $file, 'backup' => $clearance['backup']]);
            $this->recordChange($srv, "install {$file}", $clearance);

            return $r;
        });
    }

    /** POST /{server}/addons/remove { type, uuid, clearance? }: a pack Server Suite installed */
    public function addonsRemove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $data = $request->validate($this->addonRules());
        $clearance = $this->requireClearance($request, $srv);
        if ($clearance instanceof JsonResponse) {
            return $clearance;
        }

        return $this->addonAction(function () use ($srv, $data, $clearance) {
            $msg = app(\ServerSuite\Services\BedrockAddonService::class)->remove($srv->uuid, $data['type'], $data['uuid']);
            $this->audit($srv, 'addon-removed', ['addon' => $data['uuid'], 'backup' => $clearance['backup']]);
            $this->recordChange($srv, "remove add-on {$data['uuid']}", $clearance);

            return ['message' => $msg];
        });
    }

    /** POST /{server}/addons/enable { type, uuid, on }: switch a pack on or off for the world */
    public function addonsEnable(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $data = $request->validate($this->addonRules() + ['on' => ['required', 'boolean']]);

        return $this->addonAction(function () use ($srv, $data) {
            $msg = app(\ServerSuite\Services\BedrockAddonService::class)->setEnabled($srv->uuid, $data['type'], $data['uuid'], (bool) $data['on']);
            $this->audit($srv, $data['on'] ? 'addon-enabled' : 'addon-disabled', ['addon' => $data['uuid']]);

            return ['message' => $msg];
        });
    }

    /** POST /{server}/addons/move { type, uuid, delta: -1|1 }: change a switched-on pack's priority */
    public function addonsMove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $data = $request->validate($this->addonRules() + ['delta' => ['required', 'integer', 'in:-1,1']]);

        return $this->addonAction(function () use ($srv, $data) {
            app(\ServerSuite\Services\BedrockAddonService::class)->move($srv->uuid, $data['type'], $data['uuid'], (int) $data['delta']);
            $this->audit($srv, 'addon-moved', ['addon' => $data['uuid'], 'direction' => match ((int) $data['delta']) { -1 => 'up', default => 'down' }]);

            return ['message' => null];
        });
    }

    /** POST /{server}/addons/texturepack { on }: texturepack-required in server.properties */
    public function addonsTexturepack(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $this->requireBedrock($srv);
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];

        return $this->addonAction(function () use ($srv, $on) {
            app(\ServerSuite\Services\BedrockAddonService::class)->setTexturepackRequired($srv->uuid, $on);
            $this->audit($srv, $on ? 'addons-texturepack-required' : 'addons-texturepack-optional');

            return ['message' => ($on ? 'Players must now download the resource packs to join.' : 'Players may now join without the resource packs.') . ' It applies when the server restarts.'];
        });
    }

    /** 404 unless the server is a Bedrock Dedicated Server. */
    private function requireBedrock(Server $srv): void
    {
        if (! \ServerSuite\Services\BedrockAddonService::isBedrock($srv)) {
            abort(404, 'Add-ons are for Bedrock Dedicated Server.');
        }
    }

    private function addonRules(): array
    {
        return ['type' => ['required', 'string', 'in:behavior,resource'], 'uuid' => ['required', 'string', 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i']];
    }

    private function addonAction(callable $fn): JsonResponse
    {
        try {
            return response()->json(['data' => $fn()]);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    // -------------------------------------------------------------------------
    // Notifications (#26)
    // -------------------------------------------------------------------------

    /** GET /{server}/notifications: labels only, never the URLs or tokens */
    public function notifications(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => $this->notifyState($srv)]);
    }

    /** POST /{server}/notifications { type: discord|ntfy, url | server, topic, token } */
    public function notificationsAdd(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $t = $request->validate(['type' => ['required', 'in:discord,ntfy'], 'url' => ['nullable', 'string', 'max:500'],
            'server' => ['nullable', 'string', 'max:300'], 'topic' => ['nullable', 'string', 'max:64'], 'token' => ['nullable', 'string', 'max:200']]);

        return $this->notifyChange($srv, function ($n, $cfg) use ($srv, $t) {
            $n->saveServer($srv->uuid, array_merge($cfg['own'] ? $cfg['targets'] : [], [$t]), $cfg['events']);
            $this->audit($srv, 'notify-target-added', ['type' => $t['type']]);
        });
    }

    /** POST /{server}/notifications/remove { index } */
    public function notificationsRemove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $i = (int) $request->validate(['index' => ['required', 'integer', 'min:0']])['index'];

        return $this->notifyChange($srv, function ($n, $cfg) use ($srv, $i) {
            $targets = $cfg['own'] ? $cfg['targets'] : [];
            if (! isset($targets[$i])) {
                throw new \InvalidArgumentException('That target is gone already.');
            }
            array_splice($targets, $i, 1);
            $n->saveServer($srv->uuid, $targets, $cfg['events']);
            $this->audit($srv, 'notify-target-removed', []);
        });
    }

    /** POST /{server}/notifications/events { events: {name: bool} } */
    public function notificationsEvents(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $events = (array) $request->validate(['events' => ['required', 'array']])['events'];

        return $this->notifyChange($srv, function ($n, $cfg) use ($srv, $events) {
            $n->saveServer($srv->uuid, $cfg['own'] ? $cfg['targets'] : [], $events);
            $this->audit($srv, 'notify-events-saved', []);
        });
    }

    /** POST /{server}/notifications/test: send a test to every target now */
    public function notificationsTest(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $n = app(\ServerSuite\Services\Notifier::class);
        $cfg = $n->forServer($srv->uuid);
        if ($cfg['targets'] === []) {
            return response()->json(['error' => 'Add a target first.'], 422);
        }
        try {
            $results = $n->test($cfg['targets'], $cfg['own'], $srv->uuid);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $this->audit($srv, 'notify-test-sent', []);

        return response()->json(['data' => ['results' => $results] + $this->notifyState($srv)]);
    }

    /** @return array{labels: list<string>, inherits: bool, default_count: int, events: array<string, bool>, catalog: array<string, string>} */
    private function notifyState(Server $srv): array
    {
        $n = app(\ServerSuite\Services\Notifier::class);
        $cfg = $n->forServer($srv->uuid);
        $events = [];
        foreach (array_keys(\ServerSuite\Services\Notifier::EVENTS) as $e) {
            $events[$e] = (bool) ($cfg['events'][$e] ?? true);
        }

        return [
            'labels' => $cfg['own'] ? array_map([\ServerSuite\Services\Notifier::class, 'label'], $cfg['targets']) : [],
            'inherits' => ! $cfg['own'], 'default_count' => count($n->defaults()['targets']),
            'events' => $events, 'catalog' => \ServerSuite\Services\Notifier::EVENTS,
        ];
    }

    private function notifyChange(Server $srv, callable $fn): JsonResponse
    {
        $n = app(\ServerSuite\Services\Notifier::class);
        try {
            $fn($n, $n->forServer($srv->uuid));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->notifyState($srv)]);
    }

    /** POST /admin/notifications { action: add|remove|events|test, ... }: the default targets */
    public function adminNotify(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $n = app(\ServerSuite\Services\Notifier::class);
        $d = $n->defaults();
        $action = (string) $request->input('action');
        try {
            $msg = match ($action) {
                'add' => (function () use ($n, $d, $request) {
                    $t = $request->validate(['type' => ['required', 'in:discord,ntfy'], 'url' => ['nullable', 'string', 'max:500'],
                        'server' => ['nullable', 'string', 'max:300'], 'topic' => ['nullable', 'string', 'max:64'], 'token' => ['nullable', 'string', 'max:200']]);
                    $n->saveDefaults(array_merge($d['targets'], [$t]), $d['events']);

                    return 'Default target added.';
                })(),
                'remove' => (function () use ($n, $d, $request) {
                    $targets = $d['targets'];
                    $i = $request->input('index');
                    // A missing or wrong index must not remove the first target (#141 item 47).
                    if (! is_numeric($i) || ! array_key_exists((int) $i, $targets)) {
                        throw new \InvalidArgumentException('That target is no longer there. Reload the page.');
                    }
                    array_splice($targets, (int) $i, 1);
                    $n->saveDefaults($targets, $d['events']);

                    return 'Removed.';
                })(),
                'events' => (function () use ($n, $d, $request) {
                    $events = [];
                    foreach (array_keys(\ServerSuite\Services\Notifier::EVENTS) as $e) {
                        $events[$e] = $request->boolean("event_{$e}");
                    }
                    $n->saveDefaults($d['targets'], $events);

                    return 'Saved.';
                })(),
                'test' => (function () use ($n, $d) {
                    if ($d['targets'] === []) {
                        throw new \InvalidArgumentException('Add a target first.');
                    }

                    return implode(' ', array_map(fn ($r) => ($r['ok'] ? '✓ ' : '✗ ') . $r['label'] . ($r['error'] ? ": {$r['error']}" : '') . '.', $n->test($d['targets'], false)));
                })(),
                default => throw new \InvalidArgumentException('Unknown action.'),
            };
            $q = ['notify_ok' => $msg];
        } catch (\InvalidArgumentException $e) {
            $q = ['notify_error' => $e->getMessage()];
        }

        return $this->adminFlash($q, '#notifications');
    }

    // -------------------------------------------------------------------------
    // Subdomains (#14)
    // -------------------------------------------------------------------------

    /** GET /{server}/subdomains */
    public function subdomains(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\SubdomainService::class)->overview($srv)]);
    }

    /** POST /{server}/subdomains   { domain: id|"own", label } */
    public function subdomainsClaim(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'domain' => ['required'],
            'label'  => ['required', 'string', 'max:63'],
        ]);
        $domain = $data['domain'] === 'own' ? 'own' : (int) $data['domain'];

        return $this->subdomainAction(fn ($svc) => $svc->claim($srv, $data['label'], $domain, $request->user()?->id)->fqdn, $srv, 'subdomain-created');
    }

    /** POST /{server}/subdomains/{id}/release */
    public function subdomainsRelease(Request $request, string $server, int $id): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return $this->subdomainAction(fn ($svc) => $svc->release($srv, $id), $srv, 'subdomain-removed');
    }

    /** POST /{server}/subdomains/{id}/repoint */
    public function subdomainsRepoint(Request $request, string $server, int $id): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return $this->subdomainAction(fn ($svc) => $svc->repoint($srv, $id)->fqdn, $srv, 'subdomain-repointed');
    }

    /** POST /{server}/subdomains/{id}/status-card   { public: bool } (#29) */
    public function subdomainsStatusCard(Request $request, string $server, int $id): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $public = (bool) $request->validate(['public' => ['required', 'boolean']])['public'];

        return $this->subdomainAction(fn () => app(\ServerSuite\Services\StatusCardService::class)->setPublic($srv, $id, $public)->fqdn, $srv, $public ? 'status-card-enabled' : 'status-card-disabled');
    }

    /** POST /{server}/subdomains/own   { zone, token? } (blank token keeps the stored one) */
    public function subdomainsSaveOwn(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate([
            'zone'  => ['required', 'string', 'max:253'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->subdomainAction(fn ($svc) => $svc->saveOwnDomain($srv, $data['zone'], $data['token'] ?? null), $srv, 'own-domain-saved');
    }

    /** POST /{server}/subdomains/own/remove */
    public function subdomainsRemoveOwn(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return $this->subdomainAction(fn ($svc) => $svc->removeOwnDomain($srv), $srv, 'own-domain-removed');
    }

    /** POST /admin/domains   { zone, token } */
    public function adminAddDomain(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $data = $request->validate([
            'zone'  => ['required', 'string', 'max:253'],
            'token' => ['required', 'string', 'max:255'],
        ]);

        return $this->adminSubdomainRedirect(fn ($svc) => $svc->addDomain($data['zone'], $data['token']), 'Domain added.');
    }

    /** POST /admin/domains/{id}/toggle */
    public function adminToggleDomain(Request $request, int $id)
    {
        abort_unless($request->user()?->root_admin, 403);
        $domain = \ServerSuite\Models\SubdomainDomain::query()->findOrFail($id);

        return $this->adminSubdomainRedirect(fn ($svc) => $svc->setDomainEnabled($id, ! $domain->enabled), $domain->enabled ? 'Domain disabled.' : 'Domain enabled.');
    }

    /** POST /admin/domains/{id}/token   { token } (#140) */
    public function adminReplaceDomainToken(Request $request, int $id)
    {
        abort_unless($request->user()?->root_admin, 403);
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        return $this->adminSubdomainRedirect(
            fn ($svc) => $svc->replaceDomainToken($id, $data['token']),
            'Token replaced. It was checked with Cloudflare and stored encrypted; the subdomains on the domain are unchanged.'
        );
    }

    /** POST /admin/domains/{id}/remove */
    public function adminRemoveDomain(Request $request, int $id)
    {
        abort_unless($request->user()?->root_admin, 403);

        return $this->adminSubdomainRedirect(fn ($svc) => $svc->removeDomain($id), 'Domain removed.');
    }

    /** POST /admin/egg-games   { game: { <egg id>: <game key> | '' } } (#77; '' = detect) */
    public function adminSaveEggGames(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $choices = array_keys(\ServerSuite\Services\GameDetectionService::choices());
        $data = $request->validate([
            'game'   => ['required', 'array'],
            'game.*' => ['nullable', 'string', \Illuminate\Validation\Rule::in($choices)],
        ]);

        $current = \ServerSuite\Services\GameDetectionService::overrides();
        foreach ($data['game'] as $eggId => $game) {
            $game = (string) ($game ?? '');
            if (($current[(int) $eggId] ?? '') === $game || ! \Pterodactyl\Models\Egg::query()->whereKey((int) $eggId)->exists()) {
                continue;
            }
            \ServerSuite\Services\GameDetectionService::setOverride((int) $eggId, $game === '' ? null : $game);
        }

        return $this->adminFlash(['games_ok' => 'Games saved.'], '#games');
    }

    /** POST /admin/subdomain-allowances   { allowance: { <server uuid>: n } } */
    public function adminSaveAllowances(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $data = $request->validate([
            'allowance'   => ['required', 'array'],
            'allowance.*' => ['nullable', 'integer', 'between:0,100'],
        ]);

        return $this->adminSubdomainRedirect(function ($svc) use ($data) {
            foreach ($data['allowance'] as $uuid => $n) {
                if (Server::query()->where('uuid', $uuid)->exists()) {
                    $svc->setAllowance((string) $uuid, (int) ($n ?? 0));
                }
            }
        }, 'Allowances saved.');
    }

    /** Run a subdomain action; a refusal comes back as 422 with a message safe to show. */
    private function subdomainAction(callable $fn, ?Server $srv = null, ?string $event = null): JsonResponse
    {
        try {
            $result = $fn(app(\ServerSuite\Services\SubdomainService::class));
        } catch (\ServerSuite\Exceptions\SubdomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        if ($srv !== null && $event !== null) {
            $this->audit($srv, $event, is_string($result) ? ['fqdn' => $result] : []);
        }
        if ($srv !== null && in_array($event, ['subdomain-created', 'subdomain-removed'], true)) {
            $fqdn = is_string($result) ? $result : 'a subdomain';
            app(\ServerSuite\Services\Notifier::class)->notify($srv->uuid, 'subdomain', $event === 'subdomain-created' ? "Subdomain {$fqdn} claimed" : 'A subdomain was released',
                'By ' . (request()->user()?->username ?? 'a user') . '.');
        }

        return response()->json(['data' => ['ok' => true, 'fqdn' => is_string($result) ? $result : null]]);
    }

    /**
     * POST /admin/edge { action: save|test } (#2): edge mode settings, or
     * ask the router for its routes to check the URL works.
     */
    public function adminEdge(Request $request)
    {
        abort_unless($request->user()?->root_admin, 403);
        $data = $request->validate([
            'edge_enabled'    => ['nullable'],
            'edge_address'    => ['nullable', 'string', 'max:253'],
            'edge_router_url' => ['nullable', 'string', 'max:1000'],
            'edge_api_key'    => ['nullable', 'string', 'max:200'],
            'edge_api_key_clear' => ['nullable'],
            'edge_default_backend' => ['nullable', 'string', 'max:300'],
            'edge_node_backends'   => ['nullable', 'string', 'max:4000'],
        ]);
        $address = trim((string) ($data['edge_address'] ?? ''));
        $url = trim((string) ($data['edge_router_url'] ?? ''));
        $default = trim((string) ($data['edge_default_backend'] ?? ''));
        $nodes = trim((string) ($data['edge_node_backends'] ?? ''));
        if (($bad = \ServerSuite\Edge\EdgeMode::invalid($address, $url) ?? \ServerSuite\Edge\EdgeMode::invalidExtras($default, $nodes)) !== null) {
            return $this->adminFlash(['edge_error' => $bad], '#edge');
        }
        // The key is write-only: a blank field keeps the stored one.
        $newKey = trim((string) ($data['edge_api_key'] ?? ''));
        $key = $request->boolean('edge_api_key_clear') ? '' : ($newKey !== '' ? $newKey : trim((string) config('serversuite.subdomains.edge.api_key', '')));

        if ($request->input('action') === 'test') {
            $set = \ServerSuite\Edge\EdgeRouters::fromList($url, 5, $key !== '' ? $key : null);
            if ($set === null) {
                return $this->adminFlash(['edge_error' => 'Enter the router API URL first.'], '#edge');
            }
            $lines = [];
            $bad = false;
            foreach ($set->routers as $i => $r) {
                $routes = $r->routes();
                $bad = $bad || $routes === null;
                $lines[] = $r->label() . ': ' . ($routes === null ? 'no answer (GET /routes; check the URL and API key)' : count($routes) . ' route(s)');
            }

            return $this->adminFlash([$bad ? 'edge_error' : 'edge_ok' => implode('; ', $lines) . '.'], '#edge');
        }

        $on = $request->boolean('edge_enabled');
        if ($on && ($address === '' || $url === '')) {
            return $this->adminFlash(['edge_error' => 'Edge mode needs both the edge address and the router API URL.'], '#edge');
        }
        ModManagerGlobalSetting::setValue('edge_enabled', $on ? '1' : '0');
        ModManagerGlobalSetting::setValue('edge_address', $address !== '' ? $address : null);
        ModManagerGlobalSetting::setValue('edge_router_url', $url !== '' ? $url : null);
        ModManagerGlobalSetting::setValue('edge_default_backend', $default !== '' ? $default : null);
        ModManagerGlobalSetting::setValue('edge_node_backends', $nodes !== '' ? $nodes : null);
        if ($request->boolean('edge_api_key_clear')) {
            ModManagerGlobalSetting::setValue('edge_api_key', null);
        } elseif ($newKey !== '') {
            ModManagerGlobalSetting::setValue('edge_api_key', \Illuminate\Support\Facades\Crypt::encryptString($newKey));
        }
        // Apply the default route now rather than at the next sync.
        config(['serversuite.subdomains.edge.router_url' => $url, 'serversuite.subdomains.edge.api_key' => $key, 'serversuite.subdomains.edge.default_backend' => $default]);
        $failed = [];
        foreach (\ServerSuite\Edge\EdgeMode::router()?->routers ?? [] as $r) {
            try {
                $r->setDefault($default !== '' ? $default : null);
            } catch (\RuntimeException $e) {
                $failed[] = $r->label();
            }
        }
        if ($failed !== []) {
            return $this->adminFlash(['edge_ok' => 'Edge settings saved.', 'edge_error' => 'The default route could not be set on ' . implode(', ', $failed) . ' yet; the next sync (every 10 minutes) tries again.'], '#edge');
        }

        return $this->adminFlash(['edge_ok' => 'Edge settings saved.'], '#edge');
    }

    /**
     * Back to the admin page with a message (#141 item 43). Messages go in the
     * session, not the URL: exception text stays out of access logs and
     * browser history, and the page can't be made to show text from a link.
     *
     * @param array<string, mixed> $messages
     */
    private function adminFlash(array $messages, string $anchor = ''): \Illuminate\Http\RedirectResponse
    {
        return redirect()->to('/admin/extensions/serversuite' . $anchor)->with('serversuite_admin', array_map(fn ($v) => mb_substr((string) $v, 0, 500), $messages));
    }

    private function adminSubdomainRedirect(callable $fn, string $ok)
    {
        try {
            $fn(app(\ServerSuite\Services\SubdomainService::class));
            $q = ['subdomains_ok' => $ok];
        } catch (\ServerSuite\Exceptions\SubdomainException $e) {
            $q = ['subdomains_error' => $e->getMessage()];
        }

        return $this->adminFlash($q, '#subdomains');
    }

    /**
     * Resolve a Server by its UUID (passed from the React tab, which has the
     * server UUID from ServerContext). Using the UUID rather than route-model
     * binding keeps us on Blueprint's simple `web` router, matching the proven
     * reference extension.
     */
    protected function resolveServer(string $serverUuid): Server
    {
        return Server::query()->where('uuid', $serverUuid)->firstOrFail();
    }

    /** Write a Server Suite action to the server's Activity tab (#19). Never fails the request. */
    protected function audit(Server $server, string $event, array $properties = []): void
    {
        \ServerSuite\Support\SuiteActivity::log($server, 'server:serversuite.' . $event, array_filter($properties, fn ($v) => $v !== null));
    }

    /**
     * The permissions this endpoint needs (#17), checked the way Pterodactyl
     * checks its own: root admins and the owner pass, subusers need every
     * permission listed for the endpoint in PanelPermissions::ENDPOINTS.
     */
    protected function authorizeEndpoint(Request $request, Server $server, string $endpoint): void
    {
        if (! PanelPermissions::allows($request->user(), $server, $endpoint)) {
            abort(403, 'You do not have permission to do this on this server (needs ' . implode(', ', PanelPermissions::required($endpoint)) . ').');
        }

        // Suspended, or node in maintenance (#116): refused for everyone,
        // with the panel's own 409 and wording, as its client API does.
        $state = \ServerSuite\Support\ServerState::blocker($server);
        if ($state !== null) {
            abort(409, $state);
        }

        // Minecraft-only endpoints on other games (#78): the tabs are hidden,
        // and the server refuses too.
        $blocker = \ServerSuite\Support\GameGuard::endpointBlocker($server, $endpoint);
        if ($blocker !== null) {
            abort(404, $blocker);
        }
    }
}
