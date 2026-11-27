# Server Suite (PterodactylModManager) — Installation & Deployment Guide

Two artifacts, deployed in this order, always:
(both are attached to every release on the repo's **Releases** page,
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
   sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*@rc" --no-cache
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
# @rc lets Composer accept release candidates (1.0.0-rc.1) as well as stable
# releases; the panel only allows stable packages otherwise. The path
# repository only holds the version you unzipped.
sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*@rc"
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
# (require, not update: it also moves installs made with the older "*" or
#  "*@dev" constraints to "*@rc"; plain "*" rejects 1.0.0-rc.1)
sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*@rc"
grep '"version"' vendor/pterodactyl-serversuite/serversuite/composer.json  # VERIFY
sudo -u www-data php artisan migrate            # if the release notes a migration
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart
# Clear PHP's opcode cache so the new code is used. Reload whatever runs PHP:
sudo systemctl reload apache2        # Apache running PHP itself (mod_php: `apache2ctl -M | grep php_module`)
# sudo systemctl reload php8.4-fpm   # or PHP-FPM (nginx, or Apache with proxy_fcgi)

blueprint -remove serversuite && blueprint -install serversuite   # if the UI changed
```

**Knowing when to update:** Admin → Server Suite shows a notice when a newer
release is out, red for a security release, with a link to its notes and
"Dismiss until the next release". The panel asks GitHub's releases API once a
day (`serversuite:check-release` in the scheduler, 06:40); nothing about the
panel is sent, and admin default notification targets get one message per new
release. Pre-releases are offered only to panels already on a pre-release.
Turn it off under "New Server Suite releases" if the panel can't reach the
internet. It never updates anything itself.

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
sudo -u www-data composer require "pterodactyl-serversuite/serversuite:*@rc"
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
| 1.14.x | beta-2026-06 or newer | 1.0.0-rc.1+ |
| 1.15.1 | **beta-2026-08** or newer | 1.0.0-rc.1+ |
| 1.15.2 | **beta-2026-08** or newer | 1.0.0-rc.1+ (same panel code as 1.15.1) |

1.15.2 is a PHP security release (php2shell, CVE-2026-109618/109619) with no
panel code changes. Outside Docker, update PHP to 8.2.35 or 8.3.36 and every
php package with it, and turn off `session.upload_progress` (the Docker image
does both); then `systemctl reload apache2` or php-fpm.

`conf.yml`'s `target` is `beta-2026-08`. On an older Blueprint the install
only warns ("built for version ..."); it isn't a hard requirement.

Backend compatibility: every public release (1.0.0-rc.1 onwards) supports
Pterodactyl 1.14 (Laravel 11) and 1.15 (Laravel 12). Pterodactyl 2.x is not
supported yet.

## Subdomains (Cloudflare)

Servers can claim `name.yourdomain` from their **Subdomains** tab: a record for
the server's address (A for IPv4, AAAA for IPv6, or a CNAME when the
allocation's alias is a host name) plus a `_minecraft._tcp` SRV record for the
port. A name that already has any record in the zone (yours or anyone's) can't
be claimed.

1. In Cloudflare, create an API token with **Zone → DNS → Edit** for the domain
   only (My Profile → API Tokens → Create Token → "Edit zone DNS"). Never use
   the Global API Key.
2. Admin → Extensions → Server Suite → **Subdomains**: add the domain and token.
   The token is checked with Cloudflare, stored encrypted (it needs the panel's
   `APP_KEY`, so keep that safe), and never shown again.
3. Set each server's **allowance** there (default 0). "Selling" a subdomain
   happens outside the panel: take payment, then raise the allowance.

Owners can also add their own Cloudflare domain and token on the tab; those
subdomains count towards the same allowance. Records are deleted when a server
is deleted, along with the server's own-domain token. If Cloudflare doesn't
remove them then, the subdomains are kept and `serversuite:retry-subdomain-cleanup`
(hourly) tries again. The same delete removes the server's mod list, settings,
install tasks and modpack; the hourly job also clears anything servers deleted
earlier left behind, and `php artisan serversuite:prune-orphans` (`--dry-run`
to only count) does it by hand. When a server's primary
allocation changes, its records move within a minute of the server starting on
the new address (`serversuite:move-subdomains`, run every minute by the
scheduler), so players aren't sent to a port the game isn't on yet. **Move now**
on the tab moves them straight away.

**Status cards.** Owners can switch on a public status page per subdomain
(`/status/<subdomain>`, plus `/status/<subdomain>/badge.svg`). They're off by
default. `SERVERSUITE_STATUS_CARDS=false` in `.env` turns every card off (they
return 404), and `SERVERSUITE_STATUS_CARD_CACHE` sets how long a ping is cached
in seconds (default 30). The pages have no session or cookies and are throttled
to 120 requests a minute per IP; behind a CDN they can be cached for the same
time (`Cache-Control: public`). The panel pings the server's allocation itself,
so it must be able to reach the game port.

## Edge mode (mc-router)

For hosts that don't want a public port per server. Players of **Java
Minecraft** servers join on one public port (25565) of an edge, where
[mc-router](https://github.com/itzg/mc-router) reads the name they typed and
forwards them to that server's internal `IP:port`. Tested with mc-router 1.56.5
and 1.57.0.

With edge mode on, a new Java subdomain gets an address record to the edge (no
SRV record) and a route on the router. Releasing or deleting the server removes
the route; an allocation change moves the route (on the same "after the next
start" rule as DNS moves). Bedrock, PocketMine/Nukkit and other games keep
direct records: their clients don't send a host name. Subdomains made before
edge mode was turned on stay direct until you move them:

```bash
php artisan serversuite:edge-convert edge --dry-run   # what would change
php artisan serversuite:edge-convert edge             # all Java subdomains
php artisan serversuite:edge-convert edge --server=<uuid>
php artisan serversuite:edge-convert direct           # back off the edge
```

Onto the edge: the route is added, the address record points at the edge and
the SRV record is removed. Back to direct: the address record is replaced by
fresh A and SRV records to the server's own address (the name has no record
for a moment), then the route is removed.

Run mc-router on the edge (pin the version):

```yaml
services:
  mc-router:
    image: itzg/mc-router:1.57.0
    restart: unless-stopped
    ports:
      - "25565:25565"            # public
      - "10.0.0.5:8080:8080"     # API: internal address only
    volumes:
      - ./routes.json:/data/routes.json
    command: >
      -api-binding :8080
      -api-key ${MC_ROUTER_API_KEY}
      -routes-config /data/routes.json
      -connection-rate-limit 50
```

- **Protect the API.** Anyone who can reach it can send any name anywhere.
  Bind it to an internal address, firewall it so only the panel can reach
  it, and (1.57 and later) set `-api-key` and enter the same key in the
  panel. Without a key the API has no authentication at all.
- **`-routes-config`** saves API changes to the file, so routes survive a
  restart. Without it the panel puts back missing routes within 10 minutes
  (`serversuite:edge-sync`), but players can't join until then.
  Create the file first: `{"default-server": null, "mappings": {}}`.
- **`-connection-rate-limit`** defaults to 1 connection per second for the
  whole router. Raise it for more than a handful of players.
- **Players' real IPs:** add `-use-proxy-protocol` and set
  `proxies.proxy-protocol: true` in each server's
  `config/paper-global.yml` (Velocity: `haproxy-protocol = true`). A server
  without it then can't be joined through the edge, and one with it can't be
  joined directly, so switch both together.
- The route backend is the allocation's IP (not its alias). For an allocation
  on `0.0.0.0` the node's FQDN is used, so the edge must be able to reach the
  nodes on their game ports.
- **Default backend:** names without a route are dropped unless you set a
  default backend (a holding or lobby server) in the panel. The panel sets it
  on every router when saved and again with each sync.
- **Node addresses:** when a node's allocations use IPs the routers can't
  reach (public IPs, say), add `node id = internal address` lines; routes for
  that node's servers use that address with the allocation's port.
- **High availability:** list several router API URLs (one per line) and put
  the routers behind the edge address (DNS round robin, a load balancer or a
  floating IP). Every route goes to all of them; mc-router instances don't
  share routes, so the sync checks each one and fills in what it missed.

Then, under Admin → Server Suite → Subdomains → **Edge mode**: tick "Use the
edge", enter the edge address the DNS records should point at (an IP or a host
name), the router API URL(s) (`http://10.0.0.5:8080`) and key, and use
**Test the router**. The same settings can be set in `.env` (`SERVERSUITE_EDGE`,
`SERVERSUITE_EDGE_ADDRESS`, `SERVERSUITE_EDGE_ROUTER_URL`,
`SERVERSUITE_EDGE_API_KEY`, `SERVERSUITE_EDGE_DEFAULT_BACKEND`,
`SERVERSUITE_EDGE_NODE_BACKENDS`); the admin page wins.

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
- `The provided backup link resolves to a blocked address` when restoring a
  backup → your S3 endpoint is on a **private address** (self-hosted MinIO,
  Garage, etc.). Wings 1.13+ refuses to download restores from private,
  loopback or link-local addresses. On each Wings node, add the S3 hostname
  under `system.backups` in `/etc/pterodactyl/config.yml`, then
  `systemctl restart wings`:
  ```yaml
    backups:
      restore_host_allowlist:
      - s3.example.internal
  ```
  Keep it to that exact hostname so other internal addresses stay blocked.
  Public S3 (AWS, Backblaze, …) doesn't need it. Creating backups isn't
  affected, only restores.
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
