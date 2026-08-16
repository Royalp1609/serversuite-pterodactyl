<?php

namespace ServerSuite\Services;

use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Models\ServerMod;
use ServerSuite\Support\RepositoryManager;

/**
 * Reconciliation: detect drift between the server's mod directory and the
 * manifest, in both directions, and let the user resolve it explicitly.
 *
 *  - UNTRACKED: a .jar on disk with no server_mods row (pre-existing mods,
 *    SFTP uploads, or early direct deliveries). Identified hash-first via
 *    Modrinth's /version_file endpoint (files <= the configured cap only —
 *    identification is the ONE sanctioned case of file bytes passing through
 *    the panel), falling back to filename-based fuzzy search.
 *  - MISSING: a server_mods row whose file is gone from disk.
 *
 * Nothing is ever auto-written: the UI presents candidates and the user adopts
 * or forgets each one explicitly (see RECONCILIATION.md).
 */
class ReconciliationService
{
    public function __construct(
        protected DaemonFileClient $files,
        protected FileDeliveryService $delivery,
        protected RepositoryManager $repositories,
        protected ManifestService $manifest,
    ) {}

    /**
     * Scan a server and bucket the drift.
     *
     * @return array{directory: string, untracked: array, missing: array}
     *
     * @throws \ServerSuite\Exceptions\DeliveryException when the daemon listing fails
     */
    public function reconcile(object $server): array
    {
        $dir     = $this->delivery->targetDirectory($server->uuid);
        $entries = $this->files->listDirectory($server->uuid, $dir);

        $diskFiles = [];
        foreach ($entries as $e) {
            if (($e['is_file'] ?? false) && str_ends_with(strtolower($e['name']), '.jar')) {
                $diskFiles[$e['name']] = (int) ($e['size'] ?? 0);
            }
        }

        $mods       = ServerMod::forServer($server->uuid)->get();
        $byFilename = $mods->keyBy('target_filename');

        $untracked = [];
        foreach ($diskFiles as $name => $size) {
            if ($byFilename->has($name)) {
                continue;
            }
            $untracked[] = [
                'filename'   => $name,
                'size'       => $size,
                'identified' => $this->identify($server, $dir, $name, $size),
            ];
        }

        $missing = [];
        foreach ($mods as $mod) {
            if ($mod->target_filename && ! isset($diskFiles[$mod->target_filename])) {
                $missing[] = [
                    'id'                => $mod->id,
                    'mod_name'          => $mod->mod_name,
                    'installed_version' => $mod->installed_version,
                    'target_filename'   => $mod->target_filename,
                ];
            }
        }

        return ['directory' => $dir, 'untracked' => $untracked, 'missing' => $missing];
    }

    /**
     * Best-effort identification of an untracked file.
     *
     * @return array|null  null when nothing plausible was found. Otherwise:
     *   confidence 'exact'  — SHA1 matched a Modrinth version (mod + version known)
     *   confidence 'fuzzy'  — filename search produced a plausible mod (version unknown)
     */
    protected function identify(object $server, string $dir, string $filename, int $size): ?array
    {
        $cap = (int) config('serversuite.reconcile.hash_max_bytes', 10485760);

        // Hash-first, small files only (see class docblock).
        if ($size > 0 && $size <= $cap) {
            $bytes = $this->files->readFile($server->uuid, rtrim($dir, '/') . '/' . $filename, $cap);

            if ($bytes !== null) {
                $sha1 = sha1($bytes);

                foreach (['modrinth', 'hangar', 'curseforge'] as $key) {
                    try {
                        $hit = $this->repositories->driver($key)->lookupByHash($sha1);
                    } catch (\Throwable $e) {
                        $hit = null;
                    }

                    if ($hit) {
                        return [
                            'confidence'     => 'exact',
                            'source'         => $hit['mod']->source,
                            'mod_id'         => $hit['mod']->id,
                            'mod_name'       => $hit['mod']->name,
                            'icon_url'       => $hit['mod']->iconUrl,
                            'page_url'       => $hit['mod']->pageUrl,
                            'version_id'     => $hit['version']->versionId,
                            'version_number' => $hit['version']->versionNumber,
                        ];
                    }
                }
            }
        }

        // Filename fallback: strip versions/loader tokens and fuzzy-search.
        $query = $this->filenameToQuery($filename);
        if ($query === '') {
            return null;
        }

        try {
            $results = $this->repositories->driver('modrinth')->search($query, null, null, 3);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($results as $candidate) {
            similar_text(mb_strtolower($candidate->name), mb_strtolower($query), $pct);
            if ($pct >= 40) {
                return [
                    'confidence'     => 'fuzzy',
                    'source'         => $candidate->source,
                    'mod_id'         => $candidate->id,
                    'mod_name'       => $candidate->name,
                    'icon_url'       => $candidate->iconUrl,
                    'page_url'       => $candidate->pageUrl,
                    'version_id'     => null,
                    'version_number' => null,
                ];
            }
        }

        return null;
    }

    /**
     * Adopt an untracked file into the manifest. The identification is
     * re-verified server-side: for exact matches we re-fetch the mod and find
     * the named version (recovering its dependency list); fuzzy matches adopt
     * the mod with version 'unknown'; 'manual' tracks the bare file.
     */
    public function adopt(
        object $server,
        string $filename,
        string $source,
        ?string $modId,
        ?string $versionId,
        int $userId,
    ): ServerMod {
        $dir = $this->delivery->targetDirectory($server->uuid);

        $base = [
            'server_uuid'     => $server->uuid,
            'target_filename' => $filename,
            'install_path'    => $dir,
            'installed_by'    => $userId,
            'game_version'    => null,
            'loader'          => null,
            'dependencies'    => [],
        ];

        if ($source === 'manual' || ! $modId) {
            return ServerMod::create($base + [
                'source'            => 'manual',
                'source_id'         => $filename,
                'mod_name'          => preg_replace('/\.jar$/i', '', $filename),
                'mod_slug'          => null,
                'installed_version' => 'unknown',
            ]);
        }

        $driver = $this->repositories->driver($source);
        $mod    = $driver->get($modId);

        if (! $mod) {
            // Identification went stale — fall back to manual tracking rather
            // than refusing; the user explicitly chose to track this file.
            return $this->adopt($server, $filename, 'manual', null, null, $userId);
        }

        $version      = null;
        if ($versionId) {
            foreach ($driver->versions($modId) as $v) {
                if ($v->versionId === $versionId) {
                    $version = $v;
                    break;
                }
            }
        }

        return ServerMod::create($base + [
            'source'            => $mod->source,
            'source_id'         => $mod->id,
            'mod_name'          => $mod->name,
            'mod_slug'          => $mod->slug,
            'icon_url'          => $mod->iconUrl,
            'page_url'          => $mod->pageUrl,
            'installed_version' => $version?->versionNumber ?? 'unknown',
            'dependencies'      => $version?->dependencies ?? [],
        ]);
    }

    /**
     * Drop a manifest row whose file is gone (manifest-only; no file I/O).
     */
    public function forget(ServerMod $serverMod): void
    {
        $this->manifest->remove($serverMod);
    }

    /**
     * Reduce a jar filename to a fuzzy search query: drop the extension,
     * version-ish tokens, and loader/noise words.
     */
    protected function filenameToQuery(string $filename): string
    {
        $base = preg_replace('/\.jar$/i', '', $filename);
        $base = preg_replace('/\d+(\.\d+)*([+\-][\w.]+)*/', ' ', $base);
        $base = preg_replace('/\b(forge|neoforge|fabric|quilt|paper|spigot|velocity|bukkit|mc|build|release|beta|alpha|snapshot|shim|server|plugin|mod)\b/i', ' ', $base);
        $base = trim(preg_replace('/[-_.\s]+/', ' ', $base));

        $words = array_slice(array_filter(explode(' ', $base)), 0, 3);

        return implode(' ', $words);
    }
}
