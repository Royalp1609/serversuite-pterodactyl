<?php

namespace ServerSuite\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Admin-scoped egg management. Eggs are panel-wide resources (not per-server),
 * and the Application API is read-only for them, so — like the rest of Server
 * Suite — we drive Pterodactyl's own internal services directly rather than
 * going over the API.
 *
 * Import reuses the panel's EggImporterService; in-place updates reuse the
 * panel's EggUpdateImporterService (the same code paths as the admin "Import"
 * and "Update" buttons), so PTDL validation and variable create/update/prune
 * are handled by the panel itself. We just fetch/normalise the JSON and hand
 * it over.
 *
 * Two goals:
 *   1. Make missing loader eggs installable from a curated, verified catalog —
 *      no hunting for raw GitHub URLs (prerequisite for loader-switching).
 *   2. Keep every egg current. Pterodactyl stores each egg's source in its
 *      `update_url` column (from `meta.update_url` in the export). So a bulk
 *      "update all" can refresh every egg in place from its OWN source with no
 *      URLs to look up at all.
 */
class EggManagerService
{
    private const IMPORTER        = \Pterodactyl\Services\Eggs\Sharing\EggImporterService::class;
    private const UPDATE_IMPORTER = \Pterodactyl\Services\Eggs\Sharing\EggUpdateImporterService::class;
    private const NEST_CREATOR    = \Pterodactyl\Services\Nests\NestCreationService::class;
    private const EGG_MODEL        = \Pterodactyl\Models\Egg::class;
    private const NEST_MODEL       = \Pterodactyl\Models\Nest::class;

    /** Nests with egg counts, for choosing an import target. */
    public function nests(): array
    {
        if (! class_exists(self::NEST_MODEL)) {
            return [];
        }
        $model = self::NEST_MODEL;

        $out = [];
        foreach ($model::query()->withCount('eggs')->orderBy('id')->get() as $n) {
            $out[] = ['id' => $n->id, 'name' => $n->name, 'eggs' => $n->eggs_count ?? 0];
        }

        return $out;
    }

    /** Installed eggs, optionally filtered to one nest. */
    public function eggs(?int $nestId = null): array
    {
        if (! class_exists(self::EGG_MODEL)) {
            return [];
        }
        $model = self::EGG_MODEL;

        $q = $model::query()->orderBy('nest_id')->orderBy('name');
        if ($nestId !== null) {
            $q->where('nest_id', $nestId);
        }

        $out = [];
        foreach ($q->get() as $e) {
            $out[] = [
                'id'            => $e->id,
                'nest_id'       => $e->nest_id,
                'uuid'          => $e->uuid,
                'name'          => $e->name,
                'author'        => $e->author,
                'docker_images' => $e->docker_images ?? [],
                'update_url'    => $e->update_url ?? null,
                'updatable'     => trim((string) ($e->update_url ?? '')) !== '',
            ];
        }

        return $out;
    }

    /**
     * Import an egg from a PTDL JSON string into a nest. Returns a summary of
     * the created egg. Validation of the deep structure is left to the panel's
     * importer; here we only reject obvious non-eggs early with a clear message.
     *
     * @return array{id: ?int, name: string, nest_id: int, author: ?string}
     */
    public function importFromJson(string $json, int $nestId): array
    {
        if (! class_exists(self::IMPORTER)) {
            throw new \RuntimeException('Pterodactyl egg importer is unavailable on this panel.');
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! isset($decoded['name'])) {
            throw new \RuntimeException('That does not look like an egg export (not JSON, or missing "name").');
        }
        $ptdl = $decoded['meta']['version'] ?? null;
        if (! in_array($ptdl, ['PTDL_v1', 'PTDL_v2'], true)) {
            throw new \RuntimeException('Unsupported egg format' . ($ptdl ? " ({$ptdl})" : '') . '. Expected PTDL_v1 or PTDL_v2.');
        }

        [$file, $tmp] = $this->jsonToUploadedFile($json);

        try {
            $egg = app(self::IMPORTER)->handle($file, $nestId);
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::warning('[Server Suite] Egg import failed', ['nest' => $nestId, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Import failed: ' . $e->getMessage());
        }
        @unlink($tmp);

        return [
            'id'      => $egg?->id ?? null,
            'name'    => $egg?->name ?? (string) $decoded['name'],
            'nest_id' => $nestId,
            'author'  => $egg?->author ?? ($decoded['author'] ?? null),
        ];
    }

    /** Fetch egg JSON from an http(s) URL and import it into a nest. */
    public function importFromUrl(string $url, int $nestId): array
    {
        if (! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException('Provide an http(s) URL to an egg JSON file.');
        }

        $resp = $this->fetch($url);

        return $this->importFromJson($resp, $nestId);
    }

    /* ===================================================================== */
    /*  Updating eggs in place (no URLs needed for eggs that carry one)       */
    /* ===================================================================== */

    /**
     * Update a single installed egg in place. If no URL is given, the egg's
     * own stored `update_url` is used — so refreshing a previously-imported
     * egg needs nothing but its id.
     *
     * @return array{id:int, name:string, nest_id:int}
     */
    public function updateEgg(int $eggId, ?string $url = null): array
    {
        if (! class_exists(self::EGG_MODEL) || ! class_exists(self::UPDATE_IMPORTER)) {
            throw new \RuntimeException('Pterodactyl egg updater is unavailable on this panel.');
        }
        $model = self::EGG_MODEL;

        /** @var \Pterodactyl\Models\Egg|null $egg */
        $egg = $model::query()->find($eggId);
        if ($egg === null) {
            throw new \RuntimeException("Egg #{$eggId} was not found.");
        }

        $url = trim((string) ($url ?? $egg->update_url ?? ''));
        if ($url === '') {
            throw new \RuntimeException("Egg \"{$egg->name}\" has no update URL. Re-import it from a source that provides one (meta.update_url).");
        }
        if (! preg_match('#^https?://#i', $url)) {
            throw new \RuntimeException("Egg \"{$egg->name}\" has a non-http(s) update URL.");
        }

        [$file, $tmp] = $this->jsonToUploadedFile($this->fetch($url));

        try {
            $updated = app(self::UPDATE_IMPORTER)->handle($egg, $file);
        } catch (\Throwable $e) {
            @unlink($tmp);
            Log::warning('[Server Suite] Egg update failed', ['egg' => $eggId, 'url' => $url, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Update failed: ' . $e->getMessage());
        }
        @unlink($tmp);

        return [
            'id'      => (int) ($updated?->id ?? $eggId),
            'name'    => (string) ($updated?->name ?? $egg->name),
            'nest_id' => (int) ($updated?->nest_id ?? $egg->nest_id),
        ];
    }

    /**
     * Refresh EVERY installed egg (optionally scoped to one nest) from its own
     * stored source. Eggs without an update URL are skipped, not failed. One
     * bad egg never aborts the run.
     *
     * @return array{
     *   updated: array<int,array{id:int,name:string}>,
     *   skipped: array<int,array{id:int,name:string,reason:string}>,
     *   failed:  array<int,array{id:int,name:string,error:string}>,
     *   counts:  array{updated:int,skipped:int,failed:int,total:int}
     * }
     */
    public function updateAllEggs(?int $nestId = null): array
    {
        if (! class_exists(self::EGG_MODEL) || ! class_exists(self::UPDATE_IMPORTER)) {
            throw new \RuntimeException('Pterodactyl egg updater is unavailable on this panel.');
        }
        $model = self::EGG_MODEL;

        $q = $model::query()->orderBy('nest_id')->orderBy('name');
        if ($nestId !== null) {
            $q->where('nest_id', $nestId);
        }

        $updated = [];
        $skipped = [];
        $failed  = [];

        foreach ($q->get() as $egg) {
            $url = trim((string) ($egg->update_url ?? ''));
            if ($url === '') {
                $skipped[] = ['id' => (int) $egg->id, 'name' => (string) $egg->name, 'reason' => 'no update URL'];
                continue;
            }
            try {
                $this->updateEgg((int) $egg->id, $url);
                $updated[] = ['id' => (int) $egg->id, 'name' => (string) $egg->name];
            } catch (\Throwable $e) {
                $failed[] = ['id' => (int) $egg->id, 'name' => (string) $egg->name, 'error' => $e->getMessage()];
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'failed'  => $failed,
            'counts'  => [
                'updated' => count($updated),
                'skipped' => count($skipped),
                'failed'  => count($failed),
                'total'   => count($updated) + count($skipped) + count($failed),
            ],
        ];
    }

    /* ===================================================================== */
    /*  Catalog import (dynamic index — respects the nest > egg structure)    */
    /* ===================================================================== */

    /**
     * Import an egg chosen from the remote catalog into its nest.
     *
     * The nest is identified by the catalog category name (e.g. "Minecraft",
     * "Games Steamcmd"); if a nest with that name doesn't exist yet it's
     * created, so the source repo's nest > egg structure is mirrored on the
     * panel. The egg URL is validated against the catalog so this endpoint
     * can't be used to import an arbitrary URL under the guise of a catalog
     * import (use importFromUrl for that, explicitly).
     *
     * @return array{id:?int, name:string, nest_id:int, author:?string}
     */
    public function importCatalogEgg(string $nestName, string $url): array
    {
        $nestName = trim($nestName);
        $url      = trim($url);

        if ($nestName === '' || $url === '') {
            throw new \RuntimeException('Pick a nest and an egg to import.');
        }

        $catalog = app(EggCatalogService::class);
        if ($catalog->findEgg($nestName, $url) === null) {
            throw new \RuntimeException('That egg is not in the catalog for that nest. Refresh the catalog and try again.');
        }

        $nestId = $this->ensureNest($nestName);

        return $this->importFromUrl($url, $nestId);
    }

    /**
     * Find a nest by (case-insensitive) name, or create it. Returns the nest id.
     */
    public function ensureNest(string $name, ?string $description = null): int
    {
        if (! class_exists(self::NEST_MODEL)) {
            throw new \RuntimeException('Nests are unavailable on this panel.');
        }
        $model = self::NEST_MODEL;

        $existing = $model::query()->whereRaw('LOWER(name) = ?', [strtolower(trim($name))])->first();
        if ($existing) {
            return (int) $existing->id;
        }

        if (! class_exists(self::NEST_CREATOR)) {
            throw new \RuntimeException("Nest \"{$name}\" does not exist and could not be created on this panel.");
        }

        $nest = app(self::NEST_CREATOR)->handle([
            'name'        => $name,
            'description' => $description ?? 'Created by the Server Suite Egg Manager.',
        ]);

        return (int) $nest->id;
    }

    /* ===================================================================== */
    /*  Loader helpers (unchanged behaviour)                                  */
    /* ===================================================================== */

    /**
     * Installed Minecraft server-loader eggs, one per loader (proxies like
     * BungeeCord/Velocity excluded). Drives the loader picker — you can only
     * switch to a loader you have an egg for.
     *
     * @return array<int,array{egg_id:int, egg_name:string, loader:string}>
     */
    public function minecraftLoaders(): array
    {
        $serverLoaders = ['paper', 'purpur', 'spigot', 'folia', 'fabric', 'quilt', 'neoforge', 'forge', 'vanilla', 'sponge'];

        $out = [];
        $seen = [];
        foreach ($this->eggs() as $egg) {
            $slug = $this->loaderSlug((string) $egg['name']);
            // "Vanilla Bedrock" is not a Java loader (#119).
            if (str_contains(strtolower((string) $egg['name']), 'bedrock')) {
                continue;
            }
            if ($slug === null || ! in_array($slug, $serverLoaders, true) || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = ['egg_id' => (int) $egg['id'], 'egg_name' => (string) $egg['name'], 'loader' => $slug];
        }

        return $out;
    }

    /** Derive a loader slug from an egg name (neoforge before forge). */
    private function loaderSlug(string $eggName): ?string
    {
        $name = strtolower($eggName);
        foreach (['neoforge', 'fabric', 'quilt', 'forge', 'purpur', 'paper', 'spigot', 'waterfall', 'velocity', 'bungeecord', 'folia', 'sponge', 'vanilla'] as $slug) {
            if (str_contains($name, $slug)) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * Is there already an egg for this loader? Matches loosely by egg name, so
     * Phase 3 loader-switching can tell whether a target loader is installable
     * or needs importing first.
     *
     * @return array{present: bool, egg: ?array}
     */
    public function findLoaderEgg(string $loader): array
    {
        $needle = strtolower(trim($loader));
        if ($needle === '') {
            return ['present' => false, 'egg' => null];
        }

        foreach ($this->eggs() as $egg) {
            if (str_contains(strtolower((string) $egg['name']), $needle)) {
                return ['present' => true, 'egg' => $egg];
            }
        }

        return ['present' => false, 'egg' => null];
    }

    /* ===================================================================== */
    /*  Internals                                                             */
    /* ===================================================================== */

    /** GET a URL and return its body, with friendly errors. */
    private function fetch(string $url): string
    {
        try {
            $resp = Http::timeout(20)->get($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not fetch that URL: ' . $e->getMessage());
        }
        if (! $resp->successful()) {
            throw new \RuntimeException('Could not fetch that URL (HTTP ' . $resp->status() . ').');
        }

        return $resp->body();
    }

    /**
     * Write a JSON string to a temp file and wrap it in an UploadedFile in test
     * mode (so the panel importer can read it without a real HTTP upload).
     *
     * @return array{0: \Illuminate\Http\UploadedFile, 1: string} [file, tmpPath]
     */
    private function jsonToUploadedFile(string $json): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ss_egg_');
        file_put_contents($tmp, $json);
        $file = new \Illuminate\Http\UploadedFile($tmp, 'egg.json', 'application/json', null, true);

        return [$file, $tmp];
    }
}
