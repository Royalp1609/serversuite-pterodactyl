# Server Suite for Pterodactyl

Server management tools for Minecraft servers on **Pterodactyl Panel**: mod and
plugin management, scheduled backups with rotation, one-click version and
loader switching, Cloudflare subdomains, and an egg manager for admins. It all
runs inside the panel, as new tabs on each server.

It ships as two parts that are always deployed together:

| Part | What it is | File in each release |
|---|---|---|
| **Backend** | Composer package `pterodactyl-serversuite/serversuite`: all the logic, models, migrations and scheduled commands | `serversuite-pterodactyl-backend-v<version>.zip` |
| **Extension** | [Blueprint](https://blueprint.zip) extension `serversuite`: the React tabs, the admin page and the routes | `serversuite.blueprint` |

**Website and docs:** https://royalp1609.github.io/serversuite-site/

> **Coming later:** Server Suite for **Pelican** (a native Pelican plugin) and
> for **Pterodactyl v2**. Both are in development and not released yet. This
> repository is Server Suite for Pterodactyl 1.x.

---

## Screenshots

| | |
|---|---|
| ![Browse mods from Modrinth, CurseForge and Hangar](https://royalp1609.github.io/serversuite-site/assets/screenshots/pterodactyl/mods-browse.png) | ![Installed mods with update badges](https://royalp1609.github.io/serversuite-site/assets/screenshots/pterodactyl/mods-manage.png) |
| **Mods**: browse, filtered to the server's loader and version | **Manage**: update, remove, reconcile |
| ![Version and loader switching](https://royalp1609.github.io/serversuite-site/assets/screenshots/pterodactyl/versions.png) | ![Scheduled backups with rotation](https://royalp1609.github.io/serversuite-site/assets/screenshots/pterodactyl/backups.png) |
| **Versions**: switch version or loader, behind a backup | **Backups**: schedules and rotation |

More on the [website](https://royalp1609.github.io/serversuite-site/).

---

## Features

### Mods (server tab)
- **Browse and search** Modrinth, CurseForge (needs an API key) and Hangar, with
  loader and Minecraft-version filters pre-filled from the server.
- **Install with dependencies**: required libraries are resolved and installed
  for you; larger plans run as queued jobs with live progress.
- **Install queue**: add several mods to the queue (each at the version you
  pick), then install them all at once: one backup, one batch, and a library
  two of them need is installed once.
- Files are downloaded **by Wings straight to the server**; nothing large passes
  through the panel.
- **Manage**: installed mods as a list with icons and update badges; update
  one or all, and remove with warnings when something depends on a mod. Tick
  several to **remove them together** (one backup; libraries nothing needs
  afterwards are offered too, including ones the selected mods shared).
- **Scheduled update checks** (admin-tunable, optional auto-apply).
- **Reconcile**: find jars on disk the suite doesn't know about (identified by
  hash on Modrinth) and records whose file has gone, then adopt or forget them.
- **Configs**: browse `plugins/`, `config/`, `defaultconfigs/` and `kubejs/` and
  edit a file. YAML, TOML, JSON/JSON5, `.properties`, `.ini`, `.cfg` and `.conf`
  open as a **form** (switches, numbers with their ranges, choices, one-per-line
  lists); only the values you change are written, so comments and layout stay,
  and the result is read back and checked before anything is written. **Text
  editor** switches to the raw file. A save is refused if the server rewrote the
  file since you opened it. Up to 512 KiB.
- **Backup before changes** (on the Manage view: Ask, Always or Never; default
  Ask, as an in-panel dialog): before mods are installed, updated or removed,
  or a config is saved, Server Suite takes a "Pre-mod-change" backup and runs the change only once it
  has finished (a backup from the last 30 minutes is reused). The backup's name
  goes in the Activity entry, so you know what to restore. The server enforces
  it, not just the tab.
- **Crash help**: if the server crashes after a mod change (a crash report, or
  a failed start in the log, written after it), the Manage view says what
  changed, names the likely mod (from the loader's messages, or the stack
  trace), and offers to **disable** it (renamed to `.disabled`, so the loader
  skips it), points at the backup taken before the change, or shows the report.
  Nothing happens on its own.
- **Bedrock crossplay** (Paper, Purpur, Spigot, Fabric, NeoForge): one click
  installs **Geyser** and **Floodgate** (plus ViaVersion on Paper), so phones,
  consoles and Windows players can join. **Set up connections** then puts
  Geyser on the Java port with NetherNet (the only way Bedrock connects from
  27 October 2026) and GeyserMC's signaling; with a second allocation, older
  clients keep RakNet too. The card shows the address players join with.

### Modpacks (server tab)
- Install a whole **Modrinth modpack** (`.mrpack`) in one go, and update it
  later as a pack. Only on Fabric, Quilt, Forge and NeoForge servers; search
  shows packs with a build for the server's loader and Minecraft version.
- Review first: what gets downloaded, which files are skipped (client-only, or
  hosted somewhere the format doesn't allow), and what an update removes.
- The server is stopped and a "Pre-modpack" backup is taken before anything
  changes. Every file goes straight from its host to the server through Wings.
  Runs on the panel's queue worker; progress shows on the tab.
- Mods you added yourself stay; the pack's mods show a *modpack* badge.
- The server must already run the pack's loader and Minecraft version (change
  them on the **Versions** tab).

### Backups (server tab)
- **Scheduled backups with rotation**: pick how often (6 hours to weekly) and
  optionally a **fixed hour** to run at. The oldest *unlocked* backup is removed
  once the server's backup limit is reached; locked backups are always kept.
- Shows the last and next scheduled backup.
- Back up now, lock/unlock, rename, download and restore.

### Versions (server tab)
- **Change Minecraft version** for the current loader (pinned to an exact build).
- **Switch loader** (Paper, Purpur, Folia, Forge, NeoForge, Fabric and more),
  choosing the version in the same step.
- Every switch is **gated on a recent completed backup** and refuses up front if
  the server can't be reinstalled (e.g. *Skip Egg Install Script* is on), so a
  server is never left wiped.
- **Automatic upgrades** — opt in per server on a Paper server with a backup
  allocation. At the hour you pick, with no players online, it takes a backup
  and upgrades to a release that has been out for 7 days (admin setting),
  within the current line by default. Plugins are kept and moved to builds for
  the new version; it waits if one has no build or can't be identified. Servers
  in a Velocity network are skipped. If the server doesn't come back up,
  upgrades pause for it and you're told which backup to restore. It relies on
  the server answering status pings, so a Paper server with
  `hide-online-players` on reads as empty.
- On a server with **no backup allocation**, version, loader and modpack
  switches still go ahead. The confirm dialog warns you that no backup is taken.
- **Reset** the server: world only, or a clean slate.
- Switches point the egg's `DL_PATH` at the exact server jar from
  [MCJars](https://mcjars.app) (Paper, Purpur, Folia, Fabric, Velocity, ...;
  not Forge/NeoForge/Quilt, which use installers), which gets around broken
  egg install scripts. Eggs without `DL_PATH` are unaffected.
  `SERVERSUITE_MCJARS=false` turns it off.

### Properties (server tab)
- `server.properties` as a form: switches for on/off settings, number boxes,
  choices (difficulty, gamemode), and a filter box. Only the values you change
  are written; comments and the file's order stay, and a typo can't add a
  setting.
- **Save**, or **Save and restart**. When you change `level-seed`, you can reset
  the world so the new seed applies: the server is stopped first, the world
  folders are deleted only once it's offline (plugins and configs stay), then
  it starts again.

### Players (server tab)
- The **whitelist**, **operators**, **banned players** and **banned IPs** as
  lists. Add players by name (the UUID comes from Mojang, or is the offline
  UUID when `online-mode=false`). While the server runs, changes go in as
  console commands and apply at once; while it's stopped, the files are edited.
- Turn the whitelist on or off, and see who's online.
- Everything bound for the console is checked first (names, IPs, one-line
  ban reasons).

### Notifications (server tab)
- Server Suite events to **Discord webhooks** or **ntfy** topics: mod updates
  available, a scheduled backup that failed or was skipped, a crash after a mod
  change, a version or loader switch that started or failed, and subdomains
  claimed or released.
- Each server can add its own targets and pick the events; without its own, it
  uses the admin's default (**Admin → Server Suite → Default notifications**).
  **Send a test** checks every target. URLs and tokens are stored encrypted and
  never shown again.
- Repeats are held back (a day for the same updates, six hours for a failing
  backup, at most 30 messages a server an hour). Targets an owner enters must be
  on the public internet; the admin default may point at your own network.
  Needs the panel's queue worker and scheduler.

### Velocity (server tab)
- On a **Velocity** server, put your other servers behind it: tick them, name
  them, set the try order, **Apply**. That writes `velocity.toml` (`[servers]`,
  `try`, modern forwarding with the proxy's own `forwarding.secret`; sample
  forced hosts naming servers you don't have are dropped, since Velocity won't
  start with them) and, on each backend, the forwarding secret and
  `online-mode=false` (Paper/Purpur/Folia via `paper-global.yml`, Fabric/Quilt
  via FabricProxy-Lite). Each server reports what changed or what's left to do;
  taking a server out sets it back. Owner and root admins only.

### Subdomains (server tab)
- Claim `name.domain` on a domain the panel admin offers, or on the server
  owner's **own Cloudflare domain**. An A record points at the server. On
  Minecraft a SRV record carries the port, so players join with just the name;
  Rust, Valheim and other games don't look one up, so they join with
  `name.domain:port`, which the tab shows. Status cards are Minecraft-only.
- Each server has an **admin-set allowance** (default 0).
- Records are removed when a server is deleted. When its primary allocation
  changes, they move once the server has restarted on the new address (or
  straight away with **Move now**).
- **Status card** (optional, off by default): switch it on per subdomain for a
  public page at `/status/<subdomain>` on the panel's address, showing online
  or offline, players, MOTD, version and icon. The page shows nothing else
  about the server (no IP, node or ID). It comes with a badge
  (`/status/<subdomain>/badge.svg`) and an iframe snippet to copy. The panel
  pings the server at most once every 30 seconds, however many people look.

### Admin (Admin → Extensions → Server Suite)
- Repository toggles, CurseForge API key, update-check schedule, install
  behaviour.
- Backup rotation master switch, default cadence and retention, and the
  **backup timezone** (used for backup names and the fixed run hour).
- **Egg Manager**: import eggs from the community catalog (any game), keep
  installed eggs up to date, or import from a URL. See [EGGS.md](EGGS.md).
- **Subdomains**: domains offered to servers (Cloudflare tokens stored
  encrypted, never shown again) and each server's allowance.

---

## Compatibility

| Pterodactyl | Blueprint | Server Suite |
|---|---|---|
| 1.14.x (Laravel 11) | beta-2026-06 or newer | 1.0.0-rc.1+ |
| 1.15.x (Laravel 12) | **beta-2026-08** or newer | 1.0.0-rc.1+ |
| 2.x | — | not yet (coming later) |

PHP 8.2 or 8.3 · Node 22 for the Blueprint build. Every change is tested in CI
against real Pterodactyl 1.14.0 and 1.15.2 checkouts.

## Install and update

Download both files from the [latest release](https://github.com/royalp1609/serversuite-pterodactyl/releases/latest), then
follow [INSTALL.md](INSTALL.md). It covers a fresh install, updating, the steps
to repeat **after every Pterodactyl upgrade** (the upgrade removes the backend's
Composer entry), subdomain setup, and common errors.

## Application API (billing)

For billing systems such as Paymenter: the things they sell on top of a server.
Uses Pterodactyl's application API keys (**Admin → Application API**); reads need
**Servers: Read**, changes **Servers: Read & Write**. `{server}` is the panel
server id, or `external:<id>` for the billing service id (the server's
`external_id`). The same API as the Pelican plugin.

| Method | Path | Body |
|---|---|---|
| GET | `/api/application/serversuite/servers/{server}` | — returns subdomains (allowance, used, `over_allowance`, names), loader/version, backup plan and next run |
| PUT | `/api/application/serversuite/servers/{server}/subdomains` | `{"allowance": 2}` (0-100). Lowering it never removes subdomains; `over_allowance` says so |
| PUT | `/api/application/serversuite/servers/{server}/backups` | `{"enabled": true, "interval_hours": 24, "hour": 4, "keep": 5}` |

Writes are safe to repeat (the same request twice changes nothing), and real
changes show in the server's Activity tab as the key's owner. Mods, modpacks
and versions aren't sold, so they aren't in the API.

## Permissions

Server Suite uses Pterodactyl's own subuser permissions. Owners and root admins
can do everything; subusers get exactly what you tick on the server's **Users**
screen, where two new groups appear: **Mods** and **Subdomains**. Each tab only
shows for subusers holding one of its permissions, and buttons they can't use
are hidden (the server checks every request too).

| Tab | To see it | To change things |
|---|---|---|
| Mods | `mods.read` | `mods.install`, `mods.update`, `mods.remove`; `mods.configure` to adopt or forget files found on disk |
| Mods → Configs | `file.read-content` | `file.update` (the same file permissions as the File Manager) |
| Modpacks | `mods.read` | installing or updating a pack needs `mods.install`, `mods.remove` and `control.stop` (it stops the server and replaces mod files) |
| Backups | `backup.read` | `backup.create` (back up now, rename), `backup.delete` (delete, lock), `backup.restore`, `backup.download`; the schedule needs `backup.create` + `backup.delete` (rotation deletes the oldest) |
| Versions | `startup.read` | switching version or loader needs `startup.update`, `startup.docker-image`, `settings.reinstall` and `backup.create`; the reset needs `settings.reinstall` + `file.delete` |
| Properties | `file.read-content` | `file.update`; **Save and restart** also needs `control.restart`; resetting the world after a seed change needs `file.delete`, `control.stop` and `control.start` |
| Players | `players.read` | `players.manage` (add and remove on the whitelist, operators and bans; whitelist on/off) |
| Notifications | `notifications.manage` | `notifications.manage` (targets hold webhook URLs and tokens) |
| Subdomains | `subdomains.read` | `subdomains.manage` (claim, release, re-point, status cards, own domain and its token) |

Every change made through Server Suite (mods installed, updated or removed,
version and loader switches, resets, backups, subdomains) shows in the server's
**Activity** tab, with who did it.

---

## Development

```
src/          backend package (services, models, drivers, console commands)
database/     migrations (run by the panel's `php artisan migrate`)
config/       serversuite.php defaults (admin settings override them)
extension/    Blueprint extension source: conf.yml, React tabs, controller, routes, admin view
tests/        Pest tests (Orchestra Testbench, SQLite, fakes for the panel and Wings)
tools/        build-blueprint.py (package + checks), release.py
```

- Every change is tested with Pest on Laravel 11 and 12, installed into real
  Pterodactyl 1.14.0 and 1.15.2 checkouts with PHPStan, and the extension is
  type-checked against both panel frontends.
- After changing anything in `extension/`, run `python3 tools/build-blueprint.py`
  and commit the rebuilt `serversuite.blueprint`.

More detail: [PROVIDERS.md](PROVIDERS.md) (mod repositories), [EGGS.md](EGGS.md).

## Bugs and feature requests

Open an issue on [GitHub Issues](https://github.com/royalp1609/serversuite-pterodactyl/issues).
Include the Pterodactyl and Server Suite versions, and the relevant lines from
`storage/logs/laravel-*.log` (remove anything private first). Security
problems: please don't open a public issue; see [SECURITY.md](SECURITY.md).

Want to help? See [CONTRIBUTING.md](CONTRIBUTING.md) and the
[code of conduct](CODE_OF_CONDUCT.md).

Issue and pull request numbers in commit messages from before November 2026
refer to the project's earlier, private tracker, not to this repository.

## Licence

[GNU Affero General Public License v3.0](LICENSE) (AGPL-3.0-only).
