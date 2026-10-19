# Server Suite (PterodactylModManager) — Installation & Deployment Guide

Two artifacts, deployed in this order, always:
(both are attached to every release on the repo's **Releases** page in Gitea,
built by CI from the version tag):

1. **Backend** — Composer package `pterodactyl-serversuite/serversuite` (all logic)
2. **Extension** — Blueprint extension `serversuite.blueprint` (the React UI)

Panel root below is `/opt/pterodactyl-panel`; adjust to yours.

> Names: the package was renamed from ModManager to Server Suite. The Composer
> package is `pterodactyl-serversuite/serversuite`, the Blueprint identifier is
> `serversuite`. Older installs may still keep the backend in
> `packages/modmanager/`. Use whatever directory your panel's path repository
> points at.

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
   `grep '"version"' vendor/pterodactyl-serversuite/serversuite/composer.json`
   must match the version you deployed. If stuck:
   ```
   sudo -u www-data composer remove pterodactyl-serversuite/serversuite --no-update
   sudo -u www-data rm -rf vendor/pterodactyl-serversuite
   sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*" --no-cache
   ```

3. **Watch for nested-folder extraction.** The zip must land as
   `packages/serversuite/composer.json`, NOT `packages/serversuite/ServerSuite/composer.json`.

4. **Run `php artisan migrate` whenever a version ships a migration**
   (0.2.x initial tables, mod_install_tasks, 0.4.6 icon columns, 0.8.0 global
   settings). Skipping it yields "Unknown column" / missing-table errors at
   runtime, not at deploy time.

5. **`queue:restart` after every backend change** (as www-data) — stale
   workers fail with `__PHP_Incomplete_Class` or constructor mismatches.

6. **Blueprint quirks**: `.blueprint` is a ZIP; reinstall cleanly with
   `blueprint -remove serversuite` first; a failed build can leave maintenance
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

# 1. Backend: serversuite-pterodactyl-backend-v<version>.zip from the release,
#    unzipped so composer.json sits at packages/serversuite/composer.json
sudo mkdir -p packages/serversuite
sudo unzip -q serversuite-pterodactyl-backend-v<version>.zip -d packages/serversuite
sudo chown -R www-data:www-data packages/serversuite
# Point Composer at it. Required: without this, `composer require` fails with
# "could not be found in any version". A panel upgrade removes it again,
# see "After a Pterodactyl panel upgrade".
sudo -u www-data composer config repositories.serversuite '{"type": "path", "url": "packages/serversuite"}'
sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*"
grep '"version"' vendor/pterodactyl-serversuite/serversuite/composer.json
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart

# 2. Extension (requires Blueprint framework + Node 22 / yarn)
# place serversuite.blueprint in the panel root
blueprint -install serversuite
```

Then hard-refresh the browser (Ctrl+Shift+R — the JS bundle caches hard).

## Updating

```bash
cd /opt/pterodactyl-panel
# replace packages/serversuite/ contents with the new backend
sudo -u www-data composer update pterodactyl-serversuite/serversuite
grep '"version"' vendor/pterodactyl-serversuite/serversuite/composer.json  # VERIFY
sudo -u www-data php artisan migrate            # if the release notes a migration
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart

blueprint -remove serversuite && blueprint -install serversuite   # if the UI changed
```

`sudo -u www-data php artisan cache:clear` additionally flushes the 5-minute
search caches and the 10-minute per-server version-sourcing cache — use it when
testing detection changes, otherwise results look stale.

## After a Pterodactyl panel upgrade

The panel upgrade extracts a new `composer.json` and `composer.lock` over the
old ones. That **removes the path repository and the requirement for this
package**, so after `composer install` the backend is gone from `vendor/`
and every extension route returns a 500. It also overwrites the panel files
Blueprint patched. After upgrading the panel (per Pterodactyl's own upgrade
guide), before bringing it back up:

```bash
cd /opt/pterodactyl-panel
# 1. Blueprint: reinstall/upgrade it to a release that supports the new panel
#    version (table below), following Blueprint's own install guide
# 2. Re-add the backend (use the directory your backend actually lives in)
sudo -u www-data composer config repositories.serversuite '{"type": "path", "url": "packages/serversuite"}'
sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*"
grep '"version"' vendor/pterodactyl-serversuite/serversuite/composer.json   # VERIFY
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart
# 3. Reinstall the extension
blueprint -install serversuite
```

Blueprint compatibility (Blueprint support for a panel version comes in a
specific Blueprint release; older ones break on the new panel):

| Pterodactyl | Blueprint needed | Server Suite |
|---|---|---|
| 1.14.x | beta-2026-06 or newer | any |
| 1.15.1 | **beta-2026-08** or newer | 0.17.0+ |

`conf.yml`'s `target` is `beta-2026-08`. On an older Blueprint the install
only warns ("built for version ..."); it isn't a hard requirement.

Backend compatibility: 0.17.0+ supports Pterodactyl 1.14 (Laravel 11) and
1.15 (Laravel 12). 0.16.0 and older only install on Laravel 11, so on 1.15
`composer require` fails with `requires illuminate/support ^11.0`.

## Configuration

All runtime settings live in **Admin → Extensions → Server Suite** (repo
toggles, CurseForge API key, scheduler cron + auto-apply, conflict behaviour).
Saved values override `config/serversuite.php` / `.env`, which remain the
fallback. The scheduler relies on the panel's standard
`php artisan schedule:run` cron.

## Diagnostics cheat-sheet

```bash
tail -50 storage/logs/laravel.log                       # first stop for any 500
sudo -u www-data php artisan tinker --execute="..."     # single-line probes only
# prove a search at the service level (bypasses UI + controller):
#   $s=app(ServerSuite\Services\ModManagerService::class); $srv=Pterodactyl\Models\Server::first();
#   dd(collect($s->search('luckperms',$srv,5,'paper',null,'hangar'))->map(fn($m)=>$m->source.':'.$m->name)->all());
# prove version sourcing:
#   dd(app(ServerSuite\Services\ModManagerService::class)->gameVersions(Pterodactyl\Models\Server::first()));
```

Known error signatures:
- `pterodactyl-serversuite/serversuite, it could not be found in any version` →
  the path repository is missing (always after a panel upgrade). Run the
  `composer config repositories.serversuite ...` line, then require again.
- `Cannot create cache directory /var/www/.cache/composer` → harmless (no cache);
  `sudo mkdir -p /var/www/.cache && sudo chown www-data:www-data /var/www/.cache`.
- `Permission denied ... storage/framework/cache` → golden rule 1.
- `Unknown column` / missing table → golden rule 4 (run migrate).
- `__PHP_Incomplete_Class` in queue → golden rule 5.
- UI ignores filters while tinker works → browser cache or extension not
  reinstalled; hard-refresh / reinstall.
