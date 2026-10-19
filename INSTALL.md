# PterodactylModManager — Installation & Deployment Guide

Two artifacts, deployed in this order, always:

1. **Backend** — Composer package `pterodactyl-modmanager/modmanager` (all logic)
2. **Extension** — Blueprint extension `modmanager.blueprint` (the React UI)

Panel root below is `/opt/pterodactyl-panel`; adjust to yours.

---

## Golden rules (each learned from a real outage)

1. **Every composer AND artisan command runs as the web user.**
   `sudo php artisan ...` (root) writes a root-owned file under
   `storage/framework/cache` and 500s the whole panel afterwards.
   Always `sudo -u www-data php artisan ...` / `sudo -u www-data composer ...`.
   Rescue if it happens:
   `sudo chown -R www-data:www-data storage bootstrap/cache && sudo -u www-data php artisan optimize:clear`

2. **Verify the backend version actually deployed.** Composer path
   repositories silently keep stale copies.
   `grep '"version"' vendor/pterodactyl-modmanager/modmanager/composer.json`
   must match the version you deployed. If stuck:
   ```
   sudo -u www-data composer remove pterodactyl-modmanager/modmanager --no-update
   sudo -u www-data rm -rf vendor/pterodactyl-modmanager
   sudo -u www-data composer require "pterodactyl-modmanager/modmanager:*" --no-cache
   ```

3. **Watch for nested-folder extraction.** The zip must land as
   `packages/modmanager/composer.json`, NOT `packages/modmanager/ModManager/composer.json`.

4. **Run `php artisan migrate` whenever a version ships a migration**
   (0.2.x initial tables, mod_install_tasks, 0.4.6 icon columns, 0.8.0 global
   settings). Skipping it yields "Unknown column" / missing-table errors at
   runtime, not at deploy time.

5. **`queue:restart` after every backend change** (as www-data) — stale
   workers fail with `__PHP_Incomplete_Class` or constructor mismatches.

6. **Blueprint quirks**: `.blueprint` is a ZIP; reinstall cleanly with
   `blueprint -remove modmanager` first; a failed build can leave maintenance
   mode on (`sudo -u www-data php artisan up`); stale lockfile after a killed
   install → check nothing is running, delete the lock, remove + reinstall.
   Build warnings (browserslist, baseline-browser-mapping, DEP0180) are
   harmless; only `ERROR in` / exit code 1 matter.

---

## Building the extension package

The extension source lives in `extension/` (conf.yml at its root).
`serversuite.blueprint` is a plain zip of that directory. After changing
anything under `extension/`, rebuild it and commit both:

```bash
python3 tools/build-blueprint.py           # rebuild serversuite.blueprint
python3 tools/build-blueprint.py --check   # what CI runs: package in sync, versions match
```

`conf.yml`'s `version` must match `composer.json`'s: the two ship together.
CI also uploads the built package as an artifact on every run.

## Fresh install

```bash
cd /opt/pterodactyl-panel

# 1. Backend
mkdir -p packages/modmanager
# unzip the backend so composer.json sits at packages/modmanager/composer.json
# add to the panel's composer.json repositories array (one-time):
#   { "type": "path", "url": "packages/modmanager" }
sudo -u www-data composer require "pterodactyl-modmanager/modmanager:*"
grep '"version"' vendor/pterodactyl-modmanager/modmanager/composer.json
sudo -u www-data php artisan migrate
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart

# 2. Extension (requires Blueprint framework + Node 20 / yarn)
# place modmanager.blueprint in the panel root
blueprint -install modmanager
```

Then hard-refresh the browser (Ctrl+Shift+R — the JS bundle caches hard).

## Updating

```bash
cd /opt/pterodactyl-panel
# replace packages/modmanager/ contents with the new backend
sudo -u www-data composer update pterodactyl-modmanager/modmanager
grep '"version"' vendor/pterodactyl-modmanager/modmanager/composer.json  # VERIFY
sudo -u www-data php artisan migrate            # if the release notes a migration
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart

blueprint -remove modmanager && blueprint -install modmanager   # if the UI changed
```

`sudo -u www-data php artisan cache:clear` additionally flushes the 5-minute
search caches and the 10-minute per-server version-sourcing cache — use it when
testing detection changes, otherwise results look stale.

## Configuration

All runtime settings live in **Admin → Extensions → Mod Manager** (repo
toggles, CurseForge API key, scheduler cron + auto-apply, conflict behaviour).
Saved values override `config/modmanager.php` / `.env`, which remain the
fallback. The scheduler relies on the panel's standard
`php artisan schedule:run` cron.

## Diagnostics cheat-sheet

```bash
tail -50 storage/logs/laravel.log                       # first stop for any 500
sudo -u www-data php artisan tinker --execute="..."     # single-line probes only
# prove a search at the service level (bypasses UI + controller):
#   $s=app(ModManager\Services\ModManagerService::class); $srv=Pterodactyl\Models\Server::first();
#   dd(collect($s->search('luckperms',$srv,5,'paper',null,'hangar'))->map(fn($m)=>$m->source.':'.$m->name)->all());
# prove version sourcing:
#   dd(app(ModManager\Services\ModManagerService::class)->gameVersions(Pterodactyl\Models\Server::first()));
```

Known error signatures:
- `Permission denied ... storage/framework/cache` → golden rule 1.
- `Unknown column` / missing table → golden rule 4 (run migrate).
- `__PHP_Incomplete_Class` in queue → golden rule 5.
- UI ignores filters while tinker works → browser cache or extension not
  reinstalled; hard-refresh / reinstall.
