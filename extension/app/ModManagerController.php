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
            'mod_id'  => ['required', 'string'],
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
            'scheduler_interval'        => ['nullable', 'string', 'max:64'],
            'scheduler_auto_apply'      => ['nullable'],
            'auto_update_on_conflict'   => ['nullable'],
            'backup_rotation_enabled'   => ['nullable'],
            'backup_keep_default'       => ['nullable', 'integer', 'min:1', 'max:500'],
            'backup_default_interval_hours' => ['nullable', 'integer', \Illuminate\Validation\Rule::in((array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]))],
            'backup_timezone'           => ['nullable', 'timezone'],
        ]);

        // Checkboxes: absent = off. Stored as '1'/'0'.
        foreach (['repo_modrinth_enabled', 'repo_curseforge_enabled', 'repo_hangar_enabled', 'auto_update_on_conflict', 'backup_rotation_enabled'] as $box) {
            ModManagerGlobalSetting::setValue($box, $request->has($box) ? '1' : '0');
        }

        // Auto-apply inverts to the stored/notify_only semantics.
        ModManagerGlobalSetting::setValue('scheduler_notify_only', $request->has('scheduler_auto_apply') ? '0' : '1');

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

        return redirect()->to('/admin/extensions/serversuite?saved=1');
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

            return redirect()->to('/admin/extensions/serversuite?egg_imported=' . urlencode((string) ($result['name'] ?? 'egg')));
        } catch (\Throwable $e) {
            return redirect()->to('/admin/extensions/serversuite?egg_error=' . urlencode($e->getMessage()));
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

            return redirect()->to('/admin/extensions/serversuite?egg_imported=' . urlencode((string) ($result['name'] ?? 'egg')));
        } catch (\Throwable $e) {
            return redirect()->to('/admin/extensions/serversuite?egg_error=' . urlencode($e->getMessage()));
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

            return redirect()->to(
                '/admin/extensions/serversuite?eggs_updated=' . $c['updated']
                . '&eggs_skipped=' . $c['skipped']
                . '&eggs_failed=' . $c['failed']
            );
        } catch (\Throwable $e) {
            return redirect()->to('/admin/extensions/serversuite?egg_error=' . urlencode($e->getMessage()));
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
            'filename'   => ['required', 'string'],
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar,manual'],
            'mod_id'     => ['nullable', 'string'],
            'version_id' => ['nullable', 'string'],
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
            'mod_id' => ['required', 'string'],
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
            'current'   => $current,
            'available' => $available,
        ]]);
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
            'version'      => ['nullable', 'string', 'max:32'],
            'confirm_name' => ['required', 'string'],
        ]);

        // Only the loader eggs the Versions tab offers (#36): changing a
        // server's egg is otherwise an admin-only action in Pterodactyl.
        $offered = array_column(app(\ServerSuite\Services\EggManagerService::class)->minecraftLoaders(), 'egg_id');
        if (! in_array((int) $data['egg_id'], $offered, true)) {
            return response()->json(['error' => 'That egg is not one of the loaders this panel offers.'], 422);
        }

        if (trim($data['confirm_name']) !== $srv->name) {
            return response()->json(['error' => 'The server name you typed does not match.'], 422);
        }

        try {
            $result = app(\ServerSuite\Services\LoaderSwitchService::class)->switchLoader(
                $srv,
                (int) $data['egg_id'],
                $data['version'] ?? null,
                true,   // backup-gated
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
                true,                                   // requireBackup — always gated from the UI
                (bool) ($data['delete_plugins'] ?? true),
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
            'mod_id'     => ['required', 'string'],
            'version_id' => ['required', 'string'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $srv);

        return response()->json(['data' => $plan->toArray()]);
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
            'mod_id'     => ['required', 'string'],
            'version_id' => ['required', 'string'],
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
     * Resolve a Server by its UUID (passed from the React tab, which has the
     * server UUID from ServerContext). Using the UUID rather than route-model
     * binding keeps us on Blueprint's simple `web` router, matching the proven
     * reference extension.
     */
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
            $result = app(BackupRotationService::class)->backupNow($server, $data['name'] ?? null);
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

        // Re-align the server's version to the version stamped in the backup's
        // name, so the restored world matches the server's version settings.
        // Best-effort — never allowed to fail the restore.
        $aligned = null;
        try {
            $name = collect(app(PanelServerOps::class)->listBackups($server))
                ->firstWhere('uuid', $backup)?->name;
            $aligned = app(\ServerSuite\Services\VersionSwitchService::class)
                ->alignToBackupVersion($srv, $name);
        } catch (\Throwable $e) {
            // alignment is a nicety, not a requirement
        }

        $this->audit($srv, 'backup-restored', ['backup' => $backup, 'truncate' => (bool) ($data['truncate'] ?? false)]);
        return response()->json(['data' => ['ok' => true, 'aligned' => $aligned]]);
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
        $r = app(\ServerSuite\Services\PreChangeBackup::class)->clear($srv->uuid, $data['label'], array_key_exists('take', $data) && $data['take'] !== null ? (bool) $data['take'] : null);

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
        $clearance = $this->preChange($request, $srv);
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
    // Configs (#21)
    // -------------------------------------------------------------------------

    /** GET /{server}/configs?path=  (no path: the config roots that exist) */
    public function configs(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return $this->configAction(fn ($svc) => $svc->browse($srv->uuid, $request->query('path')));
    }

    /** GET /{server}/configs/file?path= : text, hash and the typed form */
    public function configsOpen(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $path = (string) $request->validate(['path' => ['required', 'string', 'max:1024']])['path'];

        return $this->configAction(fn ($svc) => $svc->open($srv->uuid, $path));
    }

    /** POST /{server}/configs/render { path, hash, values } : the text with form values applied, not saved */
    public function configsRender(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['path' => ['required', 'string', 'max:1024'], 'hash' => ['required', 'string', 'size:40'], 'values' => ['present', 'array']]);

        return $this->configAction(fn ($svc) => ['content' => $svc->render($srv->uuid, $data['path'], $data['hash'], $data['values'])]);
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

        return $this->configAction(function ($svc) use ($srv, $data, $clearance) {
            $values = $data['values'] ?? null;
            $r = $svc->save($srv->uuid, $data['path'], $data['hash'], $values, $values === null ? ($data['content'] ?? '') : null);
            if ($r['written']) {
                $changed = $r['changed'] === [] ? null : implode(', ', array_slice($r['changed'], 0, 20)) . (count($r['changed']) > 20 ? ', …' : '');
                $this->audit($srv, 'config-saved', ['file' => \ServerSuite\Services\ConfigFilesService::safe($data['path']), 'changed' => $changed, 'backup' => $clearance['backup']]);
                $this->recordChange($srv, 'edit ' . ltrim(\ServerSuite\Services\ConfigFilesService::safe($data['path']), '/'), $clearance);
            }

            return $r + $svc->open($srv->uuid, $data['path']);
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
    // Players (#27)
    // -------------------------------------------------------------------------

    /** GET /{server}/players */
    public function players(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);

        return response()->json(['data' => app(\ServerSuite\Services\PlayersService::class)->overview($srv)]);
    }

    /** POST /{server}/players { list, who, reason? } */
    public function playersAdd(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['list' => ['required', 'string', 'in:' . implode(',', array_keys(\ServerSuite\Services\PlayersService::LISTS))],
            'who' => ['required', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->playersAction($srv, fn ($svc) => $svc->add($srv, $data['list'], $data['who'], (string) ($data['reason'] ?? '')), 'players-added', ['list' => $data['list'], 'who' => trim($data['who'])]);
    }

    /** POST /{server}/players/remove { list, who } */
    public function playersRemove(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $data = $request->validate(['list' => ['required', 'string', 'in:' . implode(',', array_keys(\ServerSuite\Services\PlayersService::LISTS))],
            'who' => ['required', 'string', 'max:64']]);

        return $this->playersAction($srv, fn ($svc) => $svc->remove($srv, $data['list'], $data['who']), 'players-removed', ['list' => $data['list'], 'who' => $data['who']]);
    }

    /** POST /{server}/players/whitelist { on } */
    public function playersWhitelist(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeEndpoint($request, $srv, __FUNCTION__);
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];

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
                    array_splice($targets, (int) $request->input('index'), 1);
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

        return redirect()->to('/admin/extensions/serversuite?' . http_build_query($q) . '#notifications');
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

    /** POST /admin/domains/{id}/remove */
    public function adminRemoveDomain(Request $request, int $id)
    {
        abort_unless($request->user()?->root_admin, 403);

        return $this->adminSubdomainRedirect(fn ($svc) => $svc->removeDomain($id), 'Domain removed.');
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

    private function adminSubdomainRedirect(callable $fn, string $ok)
    {
        try {
            $fn(app(\ServerSuite\Services\SubdomainService::class));
            $q = ['subdomains_ok' => $ok];
        } catch (\ServerSuite\Exceptions\SubdomainException $e) {
            $q = ['subdomains_error' => $e->getMessage()];
        }

        return redirect()->to('/admin/extensions/serversuite?' . http_build_query($q) . '#subdomains');
    }

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
    }
}
