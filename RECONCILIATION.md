# Reconciliation (untracked / missing mods) — DESIGN NOTE (shelved)

Status: **not built yet.** Design agreed, implementation deferred. This note
preserves the decisions so a future session can build it without re-deriving
the approach. Nothing in the plugin depends on this feature; it is purely
additive.

## Problem

A server's mod directory can drift out of sync with the manifest:

- **Untracked** — a file is on disk but has no `server_mods` row. Happens when
  mods predate the plugin, were added via SFTP/file manager, or (as in our
  testing) were delivered directly through `FileDeliveryService` bypassing
  `install()`. Example: the JEI jar from the Phase 3 delivery test.
- **Missing** — a `server_mods` row exists but its file is gone from disk
  (deleted manually).

## Agreed behaviour

1. **Identification: hash first, fall back to filename.**
   - Preferred: compute the file's SHA1 and look it up via Modrinth
     `GET /version_file/{sha1}?algorithm=sha1` (authoritative — returns the
     exact project + version). CurseForge has a fingerprint-match endpoint as
     a future addition.
   - Fallback: parse the filename (strip version/loader tokens) and `search()`
     the repos for fuzzy candidates. Used when hash lookup misses OR the file
     is larger than the configured cap.

2. **User confirms each match.** Nothing is auto-written to the manifest.
   Reconciliation returns candidates with confidence; the user adopts each one
   explicitly.

3. **Detect the reverse case too.** Manifest rows whose file is missing from
   disk are flagged for the user to re-install or drop from the manifest.

## The key constraint (important)

Wings' directory listing returns name/size/is_file/mimetype/modified_at but
**no hash**. To hash a file we must read its bytes via
`DaemonFileRepository::getContent($path, $notLargerThan)`, which pulls the file
*through the panel*. This is acceptable for identification of SMALL files but
violates the "file never passes through the panel" principle that delivery
relies on.

**Resolution:** hash-lookup only when file size <= a configurable cap
(suggest `MOD_MANAGER_RECONCILE_HASH_MAX_BYTES`, default ~10 MB). Above the
cap, skip hashing and use filename matching. Delivery (pull) is unchanged and
still never routes bytes through the panel — the panel-read exception applies
ONLY to identification.

## Implementation sketch

- `ReconciliationService::reconcile($server): array`
  - `listDirectory()` the effective mod dir -> files on disk.
  - Cross-reference manifest rows by filename.
  - Buckets: `tracked`, `untracked` (with identification candidates),
    `missing`.
- Extend `DaemonFileClient` with `getFileContents($serverUuid, $path, $maxBytes): ?string`
  (Pterodactyl impl wraps `DaemonFileRepository::getContent` with its
  `FileSizeTooLargeException` guard; return null when over cap).
- Add `RepositoryContract::lookupByHash(string $sha1): ?ModResult` — Modrinth
  implements via `/version_file/{hash}`; others may no-op initially.
- `ReconciliationService::adopt(...)` — writes the manifest row for a confirmed
  match, reusing `ManifestService::record()`.
- Endpoints: `GET .../reconcile` (list buckets), `POST .../adopt` (confirm one).

## Why it's safe to defer

- Purely additive: planner, delivery, manifest, search are untouched.
- All integration seams already exist (interface, ManifestService::record,
  RepositoryContract).
- The only user-visible consequence of not having it is that manually-added
  files (like the test JEI jar) stay untracked — cosmetic only.
