<?php

namespace ServerSuite\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use ServerSuite\Services\ModManagerService;
use ServerSuite\Support\Data\ModResult;

/**
 * Read-only search endpoints for the mod manager UI.
 *
 * Routes are registered by the panel-specific UI provider (Phase 5 / Phase 8),
 * which is responsible for resolving the {server} route binding to the panel's
 * own Server model and applying the auth middleware.
 */
class ModSearchController
{
    public function __construct(protected ModManagerService $mods) {}

    /**
     * GET .../mod-manager/{server}/search?q=...
     */
    public function search(Request $request, object $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $validated = $request->validate([
            'q'     => ['required', 'string', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $result = $this->mods->searchWithContext(
            $validated['q'],
            $server,
            $validated['limit'] ?? 20,
        );

        return response()->json([
            'data' => array_map(fn (ModResult $r) => $r->toArray(), $result['data']),
            'meta' => [
                'loader'           => $result['loader'],
                'loader_confident' => $result['loader_confident'],
                'game_version'     => $result['game_version'],
            ],
        ]);
    }

    /**
     * GET .../mod-manager/{server}/detect
     *
     * Returns the detected loader and confidence so the UI can prompt the user
     * to confirm when detection is uncertain.
     */
    public function detect(Request $request, object $server): JsonResponse
    {
        $this->authorizeView($request, $server);

        $result = $this->mods->detectLoader($server);

        return response()->json(['data' => $result->toArray()]);
    }

    /**
     * GET .../mod-manager/{server}/versions/{source}/{modId}
     */
    public function versions(Request $request, object $server, string $source, string $modId): JsonResponse
    {
        $this->authorizeView($request, $server);

        $versions = $this->mods->versionsFor($source, $modId, $server);

        return response()->json([
            'data' => array_map(fn ($v) => $v->toArray(), $versions),
        ]);
    }

    protected function authorizeView(Request $request, object $server): void
    {
        $node = config('serversuite.permissions.view');

        if (! Gate::forUser($request->user())->allows($node, $server)) {
            abort(403, 'You do not have permission to view mods for this server.');
        }
    }
}
