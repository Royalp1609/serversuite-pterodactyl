# Egg Manager

Server Suite manages Pterodactyl **eggs** (server templates) and **nests**
(egg groupings) so operators can add any egg and keep it current without
hunting for raw egg JSON URLs on GitHub.

Eggs are panel-wide resources and the Application API is read-only for them, so
— like the rest of Server Suite — the Egg Manager drives Pterodactyl's own
internal services directly:

- `EggImporterService::handle(UploadedFile, int $nest)` — create a new egg.
- `EggUpdateImporterService::handle(Egg, UploadedFile)` — update an egg in
  place (variables are upserted; variables no longer present are pruned).
- `NestCreationService::handle(['name' => ..., 'description' => ...])` — create
  a nest.

All logic lives in the backend package (`ServerSuite\Services\EggManagerService`
and `EggCatalogService`), so it is never processed by Blueprint and is immune to
the admin-Blade ESC-byte issue. The extension's admin page and controller only
call into it.

---

## The catalog is read live from GitHub (not hardcoded)

`EggCatalogService` reads the community-maintained Pterodactyl egg index
published by **pelican-eggs** and presents it as a **nest > egg tree**. This
covers every game and software category (Minecraft, SteamCMD games, standalone
games, voice, databases, bots, …) and stays current with no config edits.

The index is a single Markdown document
(`content/pterodactyl.md` in `pelican-eggs/pelican-eggs.github.io`):

```
## Minecraft                                   <- a nest (category)
| [Paper](https://raw.../egg-paper.json) | …   <- an egg in that nest
```

Parsing one ~100 KB file (about 300 eggs across 9 nests) is one HTTP request,
versus crawling dozens of repos and hitting the GitHub API rate limit.

`index()` returns a `nests` tree plus `source` / `fetched_at`, and on a failed
fetch either `stale => true` (fell back to cache) or `error` (no cache).

Results are cached on **success only** (`egg_catalog.cache_ttl`, default 6h). A
transient fetch/parse failure is never cached — it falls back to the last good
copy if one exists, otherwise returns an empty tree with an `error`. Force a
re-fetch with `refresh()` (or the admin "Reload catalog" button / `?refresh=1`).

Point it at a fork or mirror via `SERVER_SUITE_EGG_INDEX_URL`.

---

## Importing respects nest > egg (and creates the nest)

`EggManagerService::importCatalogEgg(string $nestName, string $url)`:

1. validates the `(nestName, url)` pair against the live catalog (so this path
   can't be abused to import an arbitrary URL — use `importFromUrl()` explicitly
   for that),
2. finds or **creates** a nest named after the catalog category (`ensureNest()`),
3. imports the egg into it.

So the source's nest > egg structure is mirrored on the panel: choosing a
"Games Steamcmd" egg lands it in a "Games Steamcmd" nest, created if absent.

---

## Keeping eggs current: eggs remember where they came from

When an egg is imported, Pterodactyl stores `meta.update_url` from the export
into the egg's **`update_url`** column. So an already-imported egg can be
refreshed **from its own source** with nothing to look up.

- `updateEgg(int $eggId, ?string $url = null)` — update one egg in place (uses
  its stored `update_url` when no URL is passed).
- `updateAllEggs(?int $nestId = null)` — walk every egg (optionally one nest);
  each egg with an `update_url` is refreshed in place. Eggs without one are
  **skipped**, never failed, and one bad egg never aborts the run. Returns
  `updated` / `skipped` / `failed` lists plus a `counts` summary.

---

## Admin endpoints (extension)

All are root-admin only and delegate straight to the services:

| Route (prefixed `/extensions/serversuite`) | Method                  | Action |
|--------------------------------------------|-------------------------|--------|
| `GET  /admin/egg-catalog`                  | `adminEggCatalog`       | The nest > egg tree as JSON (for the cascading dropdowns). `?refresh=1` re-fetches. |
| `POST /admin/import-catalog`               | `adminImportCatalogEgg` | Import a catalog egg (`nest_name` + `egg_url`); nest auto-created. |
| `POST /admin/update-eggs`                  | `adminUpdateAllEggs`    | Refresh all installed eggs in place. |
| `POST /admin/import-egg`                   | `adminImportEgg`        | Import from an arbitrary URL (advanced). |

The admin page's nest and egg dropdowns are filled client-side from the JSON
endpoint (the Blade stays static to avoid the Blueprint ESC-byte Blade issue —
the backend is the source of truth; the page just renders what it returns).

---

## Tinker interim (if the admin Blade is ever unavailable)

The backend works regardless of the UI:

```php
$cat = app(\ServerSuite\Services\EggCatalogService::class);
$cat->index();                         // browse the nest > egg tree
$cat->refresh();                       // force a re-fetch

$svc = app(\ServerSuite\Services\EggManagerService::class);
$url = 'https://raw.githubusercontent.com/pelican-eggs/minecraft/refs/heads/main/java/fabric/egg-pterodactyl-fabric.json';
$svc->importCatalogEgg('Minecraft', $url);   // create nest if needed, import
$svc->updateAllEggs();                        // refresh everything from source
```

## Adding another egg source

The index is just a Markdown file with `## Nest` headings and
`| [Name](rawJsonUrl) | desc |` rows. To use your own list, host a file in that
shape and set `SERVER_SUITE_EGG_INDEX_URL` to it (or override
`serversuite.egg_catalog.index_url`). Eggs whose export includes
`meta.update_url` will also participate in "Update all" once imported.
