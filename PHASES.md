# Build progress tracker

Reflects actual state, which diverges from the original linear plan because
loader detection was pulled forward and hardened early.

## Done & validated against the live panel
- **Phase 1 — skeleton:** service provider, config, policies, migrations,
  models. Deployed, migrated.
- **Phase 2 — Modrinth + CurseForge drivers, search:** fan-out, ranking,
  dedupe. Modrinth validated live; CurseForge dormant until an API key is set.
- **Loader detection (was half of Phase 4):** multi-signal, confidence-scored,
  conservative game-version detection (reads MC_VERSION etc., never scrapes
  version strings). Validated live (forge @ confidence 100).
- **Phase 3 — install pipeline:** InstallPlanner (BFS dependency resolution,
  conflict/satisfied/manual classification), FileDeliveryService (Wings pull +
  size verification), queued InstallModJob, manifest writes. Validated live:
  REI + Architectury + Cloth Config installed, verified, recorded.

## FINAL STATUS — feature-complete (June 2026)

Everything below this block is historical tracking. The shipped product:
- **Browse** (Modrinth mods+plugins, Hangar; CurseForge built, awaiting key):
  provider-scoped search, server-side sort, pagination, empty-query browse-all,
  loader/version filters with auto-populated defaults.
- **Version sourcing**: env vars → version_history.json → server-jar filenames
  → Forge libraries tree → latest.log; powers auto-select + compat badges.
- **Install**: recursive dependency resolution, queued batches, Wings delivery
  (bytes never via panel), manifest tracking with icons/deps.
- **Manage**: real icons, update badges, single + bulk "Update all", scheduled
  checks (admin-tunable, optional auto-apply), dependency-aware removal with
  dependents warning + recursive orphan checkboxes.
- **Reconciliation**: untracked files identified hash-first (Modrinth SHA1) w/
  filename fallback, adopt / track-as-is; missing rows forgettable.
- **Details**: Description (safe MarkdownLite) / Versions tabs, sidebar,
  installed-version marking, per-version install.
- **Admin Settings Center**: global settings table overriding config at boot —
  repo toggles, masked CurseForge key, scheduler cron + auto-apply, conflict
  behaviour.

Remaining (not blocking "done"): CurseForge live-validation when the API key
arrives; Pest suite re-run against final code; Pelican/Filament UI (Phase 8,
explicitly out of scope for v1); styling refinements.

## NEXT: Version Changer module (decided June 2026)
Ships INSIDE this package + extension, as a SEPARATE server tab (second entry
in Components.yml Routes). New: VersionChangeService, PanelServerOps compat
seam (variable update / reinstall / backup). Reuses: version sourcing chain,
gameVersions list, ServerMod manifest for the mod-compat preflight. Safety
rails: backup gate (mandatory on downgrades — worlds don't downgrade),
loud downgrade warning, compat report, async-reinstall guidance. Display-layer
rebrand of the suite pending (identifiers unchanged). Pre-build verification:
reinstall behaviour per egg family on throwaway worlds; live class names for
StartupModificationService / ReinstallServerService / backup service.

## Outstanding (historical)
- **Phase 3 tail:** `update()` and `remove()` are built and now exercised through the UI.
- **Hangar driver (other half of Phase 4):** still a stub; `isAvailable()` returns false so the UI hides it. Needed only for Paper/Spigot/Velocity plugin loaders. Modrinth search is currently scoped to `project_type:mod` only — plugin support is future work.
- **Reconciliation (untracked/missing mods):** shelved by choice. See RECONCILIATION.md. Confirmed in live use: the JEI jar (delivered directly in early testing, no manifest row) does not appear in the Manage tab — the canonical untracked-mod case. Stale manifest rows (file removed out-of-band) showed as phantom entries until removed — the missing-file case.
- **UI Build 1 (Browse tab):** DONE & live. Filter bar (provider/size/sort/loader/version/search), results grid, install modal with live dependency resolution. Loader auto-populates from detection; version auto-populates when a concrete MC version is known (null for MC_VERSION=latest, correctly).
- **UI Build 2 (Manage tab):** DONE & live. Installed grid with version + update-available badges, client-side search, Update/Remove wired to validated endpoints.
- **UI deferred items:**
  - Details view (in-panel + open-on-site link) — deferred from Manage cards.
  - Real mod icons in Manage — manifest doesn't store icon_url; placeholder cube used.
  - Sort By control — present but relevance-only; backend sort not wired.
  - Styling pass to match design renders (functional-first styling shipped).
- **Phase 7 — update scheduler + bulk update:** CheckUpdatesCommand exists and flags pending updates; bulk one-click apply + UI wiring outstanding.
- **Phase 8 — Filament UI (Pelican):** not started.

## Planned: Admin Settings Center (proposed final phase)
A global admin area for panel-wide configuration, so settings aren't only in
config/.env. Open design decisions still to confirm when picked up:
- **Scope:** global admin page (CurseForge API key, Modrinth/Hangar/CurseForge
  enable toggles, default scheduler interval + notify-only-vs-auto-apply,
  default install behaviour like auto_update_on_conflict). Possibly also
  per-server settings surfaced in the client tab (loader/version/auto-check),
  TBD.
- **Stack:** Blueprint admin pages use Pterodactyl's Blade-based ADMIN area
  (not React like the client tabs) — so this is a Blade view + controller via
  conf.yml's admin.view/admin.controller. We already ship a minimal
  admin.view placeholder to build on.
- **API key storage:** decide between a settings table (with .env fallback),
  settings-table-only, or keeping the key in .env and only exposing toggles.
  If stored via UI, persist in a global settings table and avoid echoing the
  key back in plaintext in the form (mask it).
- **Backend:** a global settings model/table read by the drivers/services in
  preference to (or as a fallback over) .env values.

## Blueprint extension gotchas (learned the hard way)
- Blueprint find-and-replaces `{conf.yml-key}` tokens (version, name, identifier, etc.) throughout ALL extension files — including inside React JSX. Never use those words as bare `{token}` JSX expressions; `{version}` collided and got replaced with the literal version number. Pre-flight grep before packaging.
- The extension controller must NOT name a method `authorize()` — it collides with the public `authorize()` inherited from Pterodactyl's base Controller (PHP forbids narrowing visibility). Used `authorizeAbility()`.
- Controller namespace is concrete `Pterodactyl\BlueprintFramework\Extensions\{identifier}` — do NOT use the `{appcontext}` placeholder (it mangles).
- `.blueprint` files are ZIP archives, not tarballs.
- Components.yml lives in the `dashboard.components` directory, not the extension root.
- Web routes are prefixed `/extensions/{identifier}/`.

## Deploy reminders (learned the hard way)
- Bump `version` in composer.json every change (path repo won't re-copy otherwise).
- Path-repo updates can stick at an old version: verify with
  `grep '"version"' vendor/pterodactyl-modmanager/modmanager/composer.json`.
  Force a refresh with `composer remove ... --no-update && rm -rf vendor/... && composer require ... --no-cache` if stuck.
  Also watch for nested-folder extraction (packages/modmanager/ModManager/...).
- Run composer AND **every artisan command** as the web user, never root.
  Running `sudo php artisan ...` (root) boots the panel and writes a
  root-owned file under storage/framework/cache, which 500s every subsequent
  www-data request ("file_put_contents ... Permission denied"). This includes
  `queue:restart` — use `sudo -u www-data php artisan queue:restart` (it only
  sets a cache flag; no root needed) or `sudo systemctl restart pteroq`.
  Rescue when it happens: `sudo chown -R www-data:www-data storage bootstrap/cache`
  then `sudo -u www-data php artisan optimize:clear`.
- `php artisan queue:restart` after ANY job code change (else "incomplete class").
