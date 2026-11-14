<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Panel Type
    |--------------------------------------------------------------------------
    | The panel this plugin is running on. Valid values are:
    |   'pterodactyl' — Classic Pterodactyl v1.x (Blade + Vue)
    |   'pelican'     — Pelican panel (Filament v3)
    |   'auto'        — Auto-detect at boot (recommended)
    */
    'panel' => env('MOD_MANAGER_PANEL', 'auto'),

    // Errors tab (#87): how much of the end of each log to read, and how
    // long a report is cached (the tab polls).
    'errors' => [
        'tail_bytes'    => env('SERVERSUITE_ERRORS_TAIL_BYTES', 1048576),
        'cache_seconds' => 10,
    ],

    // Bedrock add-ons (#93): archives uploaded to /addons are streamed to the
    // panel to be checked before Wings unpacks them. Caps per archive.
    'bedrock' => [
        'max_archive_mb'   => env('SERVERSUITE_BEDROCK_MAX_ARCHIVE_MB', 200),
        'max_unpacked_mb'  => env('SERVERSUITE_BEDROCK_MAX_UNPACKED_MB', 1024),
        'download_timeout' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled Repositories
    |--------------------------------------------------------------------------
    | Which mod repositories are enabled globally. Individual servers can
    | disable specific repositories in their per-server settings.
    */
    'repositories' => [
        'modrinth'   => env('MOD_MANAGER_MODRINTH_ENABLED', true),
        'curseforge' => env('MOD_MANAGER_CURSEFORGE_ENABLED', true),
        'hangar'     => env('MOD_MANAGER_HANGAR_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | CurseForge API Key
    |--------------------------------------------------------------------------
    | A CurseForge API key is required for all CurseForge requests.
    | Obtain one at: https://console.curseforge.com/
    */
    'curseforge_api_key' => env('CURSEFORGE_API_KEY', ''),

    // Modpacks (#23). The .mrpack is read on the panel to preview a pack;
    // larger files are refused. Pack files themselves are fetched by Wings.
    'modpacks' => [
        'max_mrpack_bytes' => (int) env('MOD_MANAGER_MODPACK_MAX_BYTES', 268435456),
    ],

    // How long the panel waits for Wings to download one file (seconds).
    // Wings fetches the file before answering, so this must cover the
    // biggest mod or .mrpack at the node's download speed.
    'delivery' => [
        'pull_timeout' => (int) env('MOD_MANAGER_PULL_TIMEOUT', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Repository Base URLs
    |--------------------------------------------------------------------------
    | Override these if you are using mirrors or self-hosted instances.
    */
    'reconcile' => [
        // Files at or below this size are read through the panel and SHA1-
        // hashed for exact identification. Larger files use filename matching
        // only (delivery itself never routes bytes through the panel).
        'hash_max_bytes' => env('MOD_MANAGER_RECONCILE_HASH_MAX_BYTES', 10485760),
    ],

    'urls' => [
        'modrinth'   => env('MOD_MANAGER_MODRINTH_URL', 'https://api.modrinth.com/v2'),
        'curseforge' => env('MOD_MANAGER_CURSEFORGE_URL', 'https://api.curseforge.com/v1'),
        'hangar'     => env('MOD_MANAGER_HANGAR_URL', 'https://hangar.papermc.io/api/v1'),
    ],

    // Versions module (server jar/loader version sourcing + backup-gated switch).
    'versions' => [
        // PaperMC's Fill v3 API. The legacy api.papermc.io/v2 is sunset
        // (disabled 2026-07-01); v3 lives on fill.papermc.io and REQUIRES a
        // descriptive, non-generic User-Agent that includes a contact.
        'paper_url' => env('SERVER_SUITE_PAPER_URL', 'https://fill.papermc.io/v3'),
        // Mojang version manifest (vanilla) and Fabric meta — wired in later phases.
        'mojang_manifest' => env('SERVER_SUITE_MOJANG_MANIFEST', 'https://launchermeta.mojang.com/mc/game/version_manifest_v2.json'),
        'fabric_meta'     => env('SERVER_SUITE_FABRIC_META', 'https://meta.fabricmc.net/v2'),
        // Set a real contact (URL or email). Paper may reject generic UAs.
        'user_agent' => env('SERVER_SUITE_USER_AGENT', 'ServerSuite/0.13 (+set SERVER_SUITE_USER_AGENT with a contact)'),
        // #22: on a version or loader switch, point the egg's DL_PATH at the
        // direct jar from MCJars (mcjars.app), around broken install scripts.
        'mcjars' => env('SERVERSUITE_MCJARS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pterodactyl / Pelican Panel Connection
    |--------------------------------------------------------------------------
    | The panel's own base URL and application API key used when the plugin
    | needs to call the panel API internally (e.g. for file uploads).
    | These are typically already available inside the panel codebase — they
    | are listed here for environments where the plugin runs standalone.
    */
    'panel_url' => env('APP_URL', 'http://localhost'),
    'panel_api_key' => env('PTERODACTYL_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | File Delivery
    |--------------------------------------------------------------------------
    | tmp_disk: the Laravel disk used for staging downloaded mod files before
    |           pushing them to the game server via the file API.
    | request_timeout: seconds to wait for repository CDN downloads.
    */
    'file_delivery' => [
        'tmp_disk'        => env('MOD_MANAGER_TMP_DISK', 'local'),
        'tmp_path'        => 'serversuite/tmp',
        'request_timeout' => (int) env('MOD_MANAGER_DOWNLOAD_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Update Scheduler
    |--------------------------------------------------------------------------
    | interval: cron expression for the update-check command.
    | notify_only: when true the scheduler flags pending updates but does NOT
    |              apply them automatically; admins use the bulk-update button.
    */
    // Opt-in automatic version upgrades (#111). The admin page's values for the
    // first two win over these.
    'auto_upgrades' => [
        'enabled' => env('SERVER_SUITE_AUTO_UPGRADES', true),
        // A release has to have had a stable build for this long.
        'min_age_days' => env('SERVER_SUITE_AUTO_UPGRADE_MIN_AGE_DAYS', 7),
        // How often a held server is looked at again during its chosen hour.
        'check_minutes' => 10,
        'max_concurrent' => env('SERVER_SUITE_AUTO_UPGRADE_MAX_CONCURRENT', 5),
        'backup_timeout_minutes' => 60,
        'install_timeout_minutes' => 30,
        'boot_timeout_minutes' => 15,
    ],

    'scheduler' => [
        'interval'    => env('MOD_MANAGER_UPDATE_INTERVAL', '0 3 * * *'),
        'notify_only' => env('MOD_MANAGER_NOTIFY_ONLY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Rotation
    |--------------------------------------------------------------------------
    | enabled:      master switch for the rolling-backup scheduler. OFF by
    |               default so installing the suite never starts taking
    |               backups until an operator opts in.
    | keep_default: global retention target (keep-N). Per-server overrides and
    |               the admin global setting take precedence (see
    |               BackupRetentionResolver).
    | name_prefix:  prefix for auto-created rolling backups. Eviction is driven
    |               by is_locked / completed_at, NOT by this name — the prefix
    |               is purely for operators to recognise auto backups.
    | schedule:     cron for the rotation pass. MUST be wider than the panel's
    |               backup throttle window (default 600s) — a cycle does one
    |               create + at most one evict, i.e. up to 2 throttle ops.
    | timezone:     timezone used ONLY for the human-readable backup name
    |               timestamp. NULL follows the panel's app timezone. Stored
    |               timestamps remain UTC regardless.
    */
    'backups' => [
        'enabled'      => env('MOD_MANAGER_BACKUP_ROTATION_ENABLED', false),
        'keep_default' => (int) env('MOD_MANAGER_BACKUP_KEEP', 5),
        'name_prefix'  => env('MOD_MANAGER_BACKUP_PREFIX', 'Auto'),
        'schedule'     => env('MOD_MANAGER_BACKUP_SCHEDULE', '0 */12 * * *'),
        'timezone'     => env('MOD_MANAGER_BACKUP_TIMEZONE', null),

        // Phase 1.5 — per-server schedule override.
        // The command runs on this fixed tick and rotates only servers whose
        // interval has elapsed since their last run. The tick MUST be at least
        // as frequent as the smallest preset so short cadences are honoured.
        'tick_cron'              => env('MOD_MANAGER_BACKUP_TICK', '*/15 * * * *'),
        // Default cadence for servers without a per-server override (hours).
        'default_interval_hours' => (int) env('MOD_MANAGER_BACKUP_INTERVAL_HOURS', 12),
        // Allowed cadences (hours). Every value is well above the throttle
        // window, so a server admin can never pick an unsafe interval.
        'interval_presets'       => [6, 12, 24, 48, 168],
        // A backup still "running" after this many hours never finished
        // (Wings restarted, the node went away) and stops blocking rotation.
        'stale_after_hours'      => (int) env('MOD_MANAGER_BACKUP_STALE_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Install Behaviour
    |--------------------------------------------------------------------------
    | auto_update_on_conflict: when true, installing a mod that is already
    |   present at a different version updates it automatically instead of
    |   surfacing a conflict for the user to resolve.
    | queue_connection / queue_name: where InstallModJob is dispatched. Null
    |   connection uses the app default.
    */
    'install' => [
        'auto_update_on_conflict' => env('MOD_MANAGER_AUTO_UPDATE_ON_CONFLICT', false),
        'queue_connection'        => env('MOD_MANAGER_QUEUE_CONNECTION', null),
        'queue_name'              => env('MOD_MANAGER_QUEUE_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Mod Directories
    |--------------------------------------------------------------------------
    | Mapped by loader type slug. Override per-server in the settings table.
    */
    'mod_directories' => [
        'fabric'     => '/mods',
        'forge'      => '/mods',
        'neoforge'   => '/mods',
        'quilt'      => '/mods',
        'paper'      => '/plugins',
        'spigot'     => '/plugins',
        'purpur'     => '/plugins',
        'folia'      => '/plugins',
        'pufferfish' => '/plugins',
        'waterfall'  => '/plugins',
        'bungeecord' => '/plugins',
        'velocity'   => '/plugins',
        'bedrock'    => '/addons',
        'default'    => '/mods',
    ],

    // Permissions are Pterodactyl's own subuser permissions (#17): see
    // ServerSuite\Support\PanelPermissions for what each action needs.

    /*
    |--------------------------------------------------------------------------
    | Loader Detection
    |--------------------------------------------------------------------------
    | The detector inspects several signals on a server's egg to decide which
    | mod loader it runs. Signals are checked highest-confidence first.
    |
    | egg_uuids:  Map a known egg UUID directly to a loader slug. This is the
    |             most reliable signal — add your panel's egg UUIDs here.
    |             (You can find an egg's UUID in the panel admin, or via
    |             Server::first()->egg->uuid in tinker.)
    |
    | keywords:   Per-loader keyword lists matched against the egg name, then
    |             its docker images / startup command / install script. ORDER
    |             MATTERS: more specific loaders (neoforge) must precede the
    |             loaders whose name they contain (forge). The detector honours
    |             this array's order.
    */
    'detection' => [

        'egg_uuids' => [
            // Default Pterodactyl egg UUIDs (verified where possible).
            // Add or override entries for your own panel's eggs.
            '9588665d-1301-4efd-9cfc-b2e2405d31d7' => 'forge',   // "Forge Minecraft" (default egg)

            // The following are common community egg UUIDs — verify against
            // your own install and adjust as needed.
            // 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' => 'fabric',
            // 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' => 'neoforge',
            // 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' => 'quilt',
            // 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' => 'paper',
        ],

        // Order is significant: neoforge before forge, purpur/paper before spigot, etc.
        'keywords' => [
            'neoforge'   => ['neoforge'],
            'fabric'     => ['fabric'],
            'quilt'      => ['quilt'],
            'forge'      => ['forge', 'forgeserver', 'minecraftforge', 'mohist', 'arclight'],
            'folia'      => ['folia'],
            'purpur'     => ['purpur'],
            'pufferfish' => ['pufferfish'],
            'paper'      => ['paper', 'papermc'],
            'spigot'     => ['spigot'],
            'velocity'   => ['velocity'],
            'waterfall'  => ['waterfall'],
            'bungeecord' => ['bungeecord', 'bungee'],
            'bedrock'    => ['bedrock', 'geyser', 'nukkit', 'pocketmine'],
        ],

        // Minimum confidence (0–100) required to auto-store a detected loader
        // without asking the user to confirm. Below this, detection returns the
        // best guess but flags it as low-confidence so the UI can prompt.
        'auto_accept_threshold' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Games (#77)
    |--------------------------------------------------------------------------
    | Games other than Minecraft, recognised by the Steam app id in the
    | server's SRCDS_APPID variable (egg tags and nests aren't reliable).
    | Servers that are neither one of these nor Minecraft are "unknown" and
    | only get the game-neutral tabs: Dashboard, Backups, Notifications and
    | Subdomains. An admin can set an egg's game by hand on the Server Suite
    | admin page.
    |
    | startup:   words in the startup command that identify the game, for
    |            eggs without SRCDS_APPID (Pterodactyl's older Rust egg).
    | features:  extra Server Suite features for the game, on top of the
    |            game-neutral ones. List one only once it works for that game.
    |            "configs" = the Configs tab, browsing config_roots.
    |            "errors" = the Errors tab, reading logs.
    |            "players" = the Players tab (SteamPlayersService: Rust,
    |            Valheim).
    | config_roots: folders the Configs tab offers (missing ones are skipped).
    | config_hidden: files under those folders the Configs tab won't list or
    |            open, because another tab (and its permission) manages them.
    | logs:      what the Errors tab reads: path (a file, or a folder with
    |            newest = a file-name pattern: the newest match), format
    |            (oxide, carbon, bepinex; see LogErrors) and optionally
    |            when = [setting => values] (a server with FRAMEWORK=carbon
    |            reads Carbon's log, not the Oxide one left behind).
    | srv:       SRV service for subdomains (e.g. "_minecraft._tcp"); null =
    |            A record only, players type the port.
    | variables: egg variable names per setting, first match wins. Eggs for
    |            the same game drift (Pterodactyl's Rust egg 14 uses HOSTNAME,
    |            the pelican-eggs one SERVER_HOSTNAME).
    */
    'games' => [
        'rust' => [
            'name'          => 'Rust',
            'steam_app_ids' => [258550],
            'startup'       => ['RustDedicated'],
            'features'      => ['configs', 'errors', 'players'],
            'logs'          => [
                ['path' => '/oxide/logs', 'newest' => '/^oxide_[\d-]+\.txt$/', 'format' => 'oxide', 'when' => ['framework' => ['oxide']]],
                ['path' => '/carbon/logs/Carbon.Core.log', 'format' => 'carbon', 'when' => ['framework' => ['carbon']]],
            ],
            // Oxide and Carbon plugin configs and data, and the server's own cfg (the eggs set +server.identity "rust").
            'config_roots'  => ['/oxide/config', '/oxide/data', '/carbon/configs', '/carbon/data', '/server/rust/cfg'],
            // Owners, moderators and bans: the Players tab's (players.manage), not the config editor's.
            'config_hidden' => ['/server/rust/cfg/users.cfg', '/server/rust/cfg/bans.cfg'],
            'srv'           => null,
            'mods_label'    => 'Plugins',
            'variables'     => [
                'hostname'   => ['SERVER_HOSTNAME', 'HOSTNAME'],
                'framework'  => ['FRAMEWORK'],
                'query_port' => ['QUERY_PORT'],
                'rcon_port'  => ['RCON_PORT'],
                'app_port'   => ['APP_PORT'],
            ],
        ],
        'valheim' => [
            'name'          => 'Valheim',
            'steam_app_ids' => [896660],
            'startup'       => ['valheim_server'],
            'features'      => ['configs', 'errors', 'players'],
            'config_roots'  => ['/BepInEx/config'],
            'logs'          => [
                ['path' => '/BepInEx/LogOutput.log', 'format' => 'bepinex'],
            ],
            'srv'           => null,
            'mods_label'    => 'Mods',
            'variables'     => [
                'server_name' => ['SERVER_NAME'],
                'world'       => ['WORLD'],
                'modpack'     => ['V_MODPACK'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Egg Catalog
    |--------------------------------------------------------------------------
    | Server Suite doesn't ship a hardcoded egg list. It reads the community-
    | maintained Pterodactyl egg index published by pelican-eggs and presents
    | it as a nest > egg tree in the admin Egg Manager. This covers every game
    | and software category (not just Minecraft) and stays current on its own.
    |
    | The index is a single Markdown document whose "## Heading" lines are
    | nests and whose "| [Egg](rawUrl) | desc |" rows are eggs. Picking an egg
    | imports it into a nest named after its category — created if it doesn't
    | exist yet, so the source structure is mirrored on the panel.
    |
    | index_url: the raw Markdown index to read. Override to point at a fork or
    |            a self-hosted mirror.
    | cache_ttl: seconds to cache a SUCCESSFUL parse (min 300). Failures are
    |            never cached; a transient error falls back to the last good
    |            copy if one exists.
    */
    'egg_catalog' => [
        'index_url' => env(
            'SERVER_SUITE_EGG_INDEX_URL',
            'https://raw.githubusercontent.com/pelican-eggs/pelican-eggs.github.io/main/content/pterodactyl.md'
        ),
        'cache_ttl' => (int) env('SERVER_SUITE_EGG_INDEX_TTL', 21600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Subdomains (Cloudflare)
    |--------------------------------------------------------------------------
    | Domains and their API tokens are added by a panel admin under Admin ->
    | Extensions -> Server Suite (stored encrypted), and each server's
    | allowance is set there too (default 0). Owners can also add their own
    | Cloudflare domain. Use scoped tokens: Zone > DNS > Edit on that zone only.
    |
    | reserved: labels nobody can claim on any domain.
    */
    'subdomains' => [
        'reserved' => ['www', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'admin', 'panel', 'api',
                       'ns1', 'ns2', 'mc', 'root', 'node', 'wings', 'status', 'billing'],
    ],

    // Public status cards (#29): /status/<fqdn> and /status/<fqdn>/badge.svg,
    // for subdomains whose owner switched them on. Off here hides them all.
    'status_cards' => [
        'enabled'       => env('SERVERSUITE_STATUS_CARDS', true),
        'cache_seconds' => env('SERVERSUITE_STATUS_CARD_CACHE', 30),
    ],

];

