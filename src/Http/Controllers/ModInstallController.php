<?php

namespace ServerSuite\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Models\ServerMod;
use ServerSuite\Models\ModInstallTask;
use ServerSuite\Exceptions\DeliveryException;

/**
 * Write endpoints for the mod manager: plan, install, poll, update, remove.
 *
 * Routes + {server} binding are registered by the panel UI provider. Each
 * action authorizes against the relevant permission node via the policy.
 */
class ModInstallController
{
    public function __construct(protected ModManagerService $mods) {}

    /**
     * POST .../mod-manager/{server}/plan
     * Body: { source, mod_id, version_id }
     *
     * Read-only: returns the install plan (dependencies, conflicts) for the UI
     * to display before the user confirms.
     */
    public function plan(Request $request, object $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');

        $data = $request->validate([
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'     => ['required', 'string'],
            'version_id' => ['required', 'string'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $server);

        return response()->json(['data' => $plan->toArray()]);
    }

    /**
     * POST .../mod-manager/{server}/install
     * Body: { source, mod_id, version_id, queue?: bool }
     *
     * Executes a (confirmed) plan. Returns sync result or a batch id to poll.
     */
    public function install(Request $request, object $server): JsonResponse
    {
        $this->authorize($request, $server, 'install');

        $data = $request->validate([
            'source'     => ['required', 'string', 'in:modrinth,curseforge,hangar'],
            'mod_id'     => ['required', 'string'],
            'version_id' => ['required', 'string'],
            'queue'      => ['nullable', 'boolean'],
        ]);

        $plan = $this->mods->planInstall($data['source'], $data['mod_id'], $data['version_id'], $server);

        try {
            $result = $this->mods->executePlan(
                $plan,
                $server,
                (int) $request->user()->id,
                forceQueue: (bool) ($data['queue'] ?? false),
            );
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * GET .../mod-manager/{server}/tasks/{batch}
     * Poll the status of a queued install batch.
     */
    public function taskStatus(Request $request, object $server, string $batch): JsonResponse
    {
        $this->authorize($request, $server, 'view');

        $tasks = ModInstallTask::forBatch($batch)
            ->where('server_uuid', $server->uuid)
            ->get(['id', 'mod_name', 'kind', 'status', 'error', 'version_number']);

        $done = $tasks->every(fn ($t) => in_array($t->status, ['success', 'failed', 'skipped'], true));

        return response()->json([
            'data' => $tasks,
            'meta' => [
                'complete'  => $done,
                'total'     => $tasks->count(),
                'succeeded' => $tasks->where('status', 'success')->count(),
                'failed'    => $tasks->where('status', 'failed')->count(),
            ],
        ]);
    }

    /**
     * POST .../mod-manager/{server}/mods/{serverMod}/update
     */
    public function update(Request $request, object $server, ServerMod $serverMod): JsonResponse
    {
        $this->authorize($request, $server, 'update');
        $this->assertBelongsToServer($serverMod, $server);

        try {
            $updated = $this->mods->update($serverMod, $server);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json([
            'data' => [
                'mod_name'          => $updated->mod_name,
                'installed_version' => $updated->installed_version,
            ],
        ]);
    }

    /**
     * DELETE .../mod-manager/{server}/mods/{serverMod}
     */
    public function destroy(Request $request, object $server, ServerMod $serverMod): JsonResponse
    {
        $this->authorize($request, $server, 'remove');
        $this->assertBelongsToServer($serverMod, $server);

        try {
            $this->mods->remove($serverMod, $server);
        } catch (DeliveryException $e) {
            return response()->json(['error' => $e->getMessage(), 'reason' => $e->reason], 422);
        }

        return response()->json(['data' => ['removed' => true]]);
    }

    // -------------------------------------------------------------------------

    protected function authorize(Request $request, object $server, string $ability): void
    {
        $node = config("serversuite.permissions.{$ability}");

        if (! Gate::forUser($request->user())->allows($node, $server)) {
            abort(403, "You do not have permission to {$ability} mods for this server.");
        }
    }

    /**
     * Guard against operating on a manifest entry that belongs to another server.
     */
    protected function assertBelongsToServer(ServerMod $serverMod, object $server): void
    {
        if ($serverMod->server_uuid !== $server->uuid) {
            abort(404);
        }
    }
}
