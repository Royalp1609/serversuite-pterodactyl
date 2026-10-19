<?php

namespace Pterodactyl\BlueprintFramework\Extensions\serversuite;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'update');

        $result = $this->mods->updateAll($srv);

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'install');

        $data = $request->validate([
            'filename'   => ['required', 'string'],
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar,manual'],
            'mod_id'     => ['nullable', 'string'],
            'version_id' => ['nullable', 'string'],
        ]);

        $row = $recon->adopt(
            $srv,
            $data['filename'],
            $data['source'],
            $data['mod_id'] ?? null,
            $data['version_id'] ?? null,
            (int) ($request->user()->id ?? 0),
        );

        return response()->json(['data' => ['adopted' => true, 'id' => $row->id]]);
    }

    /**
     * POST /{server}/reconcile/forget   { id }
     * Drop a manifest row whose file is gone from disk (manifest-only).
     */
    public function reconcileForget(Request $request, string $server, ReconciliationService $recon): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'remove');

        $data = $request->validate(['id' => ['required', 'integer']]);

        $mod = ServerMod::where('id', $data['id'])->where('server_uuid', $srv->uuid)->firstOrFail();
        $recon->forget($mod);

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'update');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'update');

        $data = $request->validate([
            'egg_id'       => ['required', 'integer'],
            'version'      => ['nullable', 'string', 'max:32'],
            'confirm_name' => ['required', 'string'],
        ]);

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
            return response()->json(['error' => $e->getMessage()], 422);
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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'update');

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
            return response()->json(['error' => $e->getMessage()], 422);
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
        $this->authorizeAbility($request, $srv, 'install');

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
        $this->authorizeAbility($request, $srv, 'install');

        $data = $request->validate([
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'     => ['required', 'string'],
            'version_id' => ['required', 'string'],
            'queue'      => ['nullable', 'boolean'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $srv);

        try {
            $result = $this->mods->executePlan($plan, $srv, (int) $request->user()->id, (bool) ($data['queue'] ?? false));
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * GET /{server}/tasks/{batch}
     */
    public function tasks(Request $request, string $server, string $batch): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'view');

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
        $this->authorizeAbility($request, $srv, 'update');

        $mod = ServerMod::where('id', $serverMod)->where('server_uuid', $srv->uuid)->firstOrFail();

        if ($mod->source === 'manual') {
            return response()->json(['error' => 'Manually tracked files have no repository to update from.'], 422);
        }

        try {
            $updated = $this->mods->update($mod, $srv);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

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
        $this->authorizeAbility($request, $srv, 'remove');

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

        try {
            $this->mods->remove($mod, $srv, $force);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

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
        $this->authorizeAbility($request, $srv, 'view');

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
                'last_run_at'            => optional($setting?->backup_last_run_at)->toIso8601String(),
            ],
        ]]);
    }

    /**
     * POST /{server}/backups/settings   { enabled }
     * Per-server opt-in to scheduled rotation. Retention is fixed to the
     * server's backup limit and is not editable here.
     */
    public function backupsSaveSettings(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        $presets = array_values((array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]));

        $data = $request->validate([
            'enabled'        => ['nullable', 'boolean'],
            // null = use the global default; otherwise must be a bounded preset
            // so a server admin can never pick an unsafe (throttle-tripping) cadence.
            'interval_hours' => ['nullable', 'integer', \Illuminate\Validation\Rule::in($presets)],
        ]);

        // Retention is governed by the server's backup limit (panel admins only).
        // Server admins control opt-in to scheduled rotation and their cadence.
        $setting = ModManagerSetting::forServer($server);
        $setting->backup_rotation_enabled = (bool) ($data['enabled'] ?? false);
        $setting->backup_interval_hours = $data['interval_hours'] ?? null;
        $setting->save();

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST /{server}/backups/rotate
     * Run one rotation cycle now (create + evict-oldest per policy).
     */
    public function backupsRotate(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        $result = app(BackupRotationService::class)->rotate($server);

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * POST /{server}/backups   { name? }
     * Manual user-triggered backup with an optional custom name.
     */
    public function backupsCreate(Request $request, string $server): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $result = app(BackupRotationService::class)->backupNow($server, $data['name'] ?? null);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * POST /{server}/backups/{backup}/rename   { name }
     */
    public function backupsRename(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
        ]);

        try {
            app(PanelServerOps::class)->renameBackup($server, $backup, $data['name']);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * POST /{server}/backups/{backup}/lock   { locked }
     * Lock/unlock a backup (locked backups are protected from eviction).
     */
    public function backupsLock(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        $data = $request->validate(['locked' => ['required', 'boolean']]);

        try {
            app(PanelServerOps::class)->setBackupLocked($server, $backup, $data['locked']);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * DELETE /{server}/backups/{backup}
     */
    public function backupsDelete(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

        try {
            app(PanelServerOps::class)->deleteBackup($server, $backup);
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['ok' => true]]);
    }

    /**
     * GET /{server}/backups/{backup}/download
     * Returns a short-lived signed URL the browser can open.
     */
    public function backupsDownload(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'view');

        try {
            $url = app(PanelServerOps::class)->downloadUrl($server, $backup, $request->user());
        } catch (BackupException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    /**
     * POST /{server}/backups/{backup}/restore   { truncate? }
     * Destructive: restores the backup over the server's files.
     */
    public function backupsRestore(Request $request, string $server, string $backup): JsonResponse
    {
        $srv = $this->resolveServer($server);
        $this->authorizeAbility($request, $srv, 'update');

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

        return response()->json(['data' => ['ok' => true, 'aligned' => $aligned]]);
    }

    protected function resolveServer(string $serverUuid): Server
    {
        return Server::query()->where('uuid', $serverUuid)->firstOrFail();
    }

    /**
     * Authorize an action against the installed ServerModPolicy via the gate
     * nodes the package registers (mod-manager.view/install/update/remove).
     */
    protected function authorizeAbility(Request $request, Server $server, string $ability): void
    {
        $node = config("serversuite.permissions.{$ability}");

        if (! $node || ! Gate::forUser($request->user())->allows($node, $server)) {
            abort(403, "You do not have permission to {$ability} mods for this server.");
        }
    }
}
