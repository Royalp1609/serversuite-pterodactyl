@php
    // Effective values: admin-saved overrides (already mapped onto config at
    // boot by the backend provider), falling back to config/.env defaults.
    $repoModrinth   = (bool) config('serversuite.repositories.modrinth', true);
    $repoCurseforge = (bool) config('serversuite.repositories.curseforge', true);
    $repoHangar     = (bool) config('serversuite.repositories.hangar', true);
    $cfKeySet       = trim((string) config('serversuite.curseforge_api_key', '')) !== '';
    $interval       = config('serversuite.scheduler.interval', '0 3 * * *');
    $notifyOnly     = (bool) config('serversuite.scheduler.notify_only', true);
    $autoConflict   = (bool) config('serversuite.install.auto_update_on_conflict', false);
    $backupEnabled  = (bool) config('serversuite.backups.enabled', false);
    $backupKeep     = (int) config('serversuite.backups.keep_default', 5);
    $backupSchedule = config('serversuite.backups.schedule', '0 */12 * * *');
    $backupDefaultInterval = (int) config('serversuite.backups.default_interval_hours', 12);
    $backupPresets = (array) config('serversuite.backups.interval_presets', [6, 12, 24, 48, 168]);
    $intervalLabels = [6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Daily', 48 => 'Every 2 days', 168 => 'Weekly'];
    $backupTz       = (string) config('serversuite.backups.timezone', '');
    $appTz          = config('app.timezone', 'UTC');
@endphp

<div class="row">
    <div class="col-xs-12 col-md-8">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Mod Manager — Global Settings</h3>
            </div>
            <form method="POST" action="/extensions/serversuite/admin/settings">
                @csrf
                <div class="box-body">
                    @if (request()->query('saved'))
                        <div class="alert alert-success">Settings saved. They take effect on the next request.</div>
                    @endif

                    <h4>Repositories</h4>
                    <div class="form-group">
                        <label><input type="checkbox" name="repo_modrinth_enabled" value="1" {{ $repoModrinth ? 'checked' : '' }}> Modrinth</label><br>
                        <label><input type="checkbox" name="repo_hangar_enabled" value="1" {{ $repoHangar ? 'checked' : '' }}> Hangar (PaperMC)</label><br>
                        <label><input type="checkbox" name="repo_curseforge_enabled" value="1" {{ $repoCurseforge ? 'checked' : '' }}> CurseForge</label>
                        <p class="text-muted small">Disabled repositories are hidden from every server's Mod Manager tab.</p>
                    </div>

                    <h4>CurseForge API key</h4>
                    <div class="form-group">
                        <input type="password" class="form-control" name="curseforge_api_key" autocomplete="off"
                               placeholder="{{ $cfKeySet ? '•••••••• (a key is configured — leave blank to keep it)' : 'No key configured' }}">
                        <label style="font-weight:normal; margin-top:6px;">
                            <input type="checkbox" name="clear_curseforge_api_key" value="1"> Clear the stored key
                        </label>
                        <p class="text-muted small">Required for CurseForge searches and installs. Obtain one at console.curseforge.com. The key is stored in the panel database and never displayed back.</p>
                    </div>

                    <h4>Update scheduler</h4>
                    <div class="form-group">
                        <label>Check interval (cron expression)</label>
                        <input type="text" class="form-control" name="scheduler_interval" value="{{ $interval }}" placeholder="0 3 * * *">
                        <label style="font-weight:normal; margin-top:8px;">
                            <input type="checkbox" name="scheduler_auto_apply" value="1" {{ $notifyOnly ? '' : 'checked' }}>
                            Apply updates automatically (otherwise updates are only flagged, and admins use the "Update all" button)
                        </label>
                    </div>

                    <h4>Automatic version upgrades</h4>
                    <div class="form-group">
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="auto_upgrades_enabled" value="1" {{ config('serversuite.auto_upgrades.enabled', true) ? 'checked' : '' }}>
                            Allow automatic upgrades on this panel (each server still opts in on its Versions tab; only Paper servers with a backup allocation can)
                        </label>
                        <label style="display:block; margin-top:8px;">Days a release must be out first</label>
                        <input type="number" min="0" max="365" class="form-control" style="max-width:120px;" name="auto_upgrades_min_age_days" value="{{ config('serversuite.auto_upgrades.min_age_days', 7) }}">
                        <p class="text-muted small">Turning this off stops new upgrades on every server. One already past its backup finishes.</p>
                    </div>

                    <h4>Install behaviour</h4>
                    <div class="form-group">
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="auto_update_on_conflict" value="1" {{ $autoConflict ? 'checked' : '' }}>
                            When installing a mod that is already present at a different version, update it automatically instead of asking
                        </label>
                    </div>

                    <h4>Backup rotation</h4>
                    <div class="form-group">
                        <label style="font-weight:normal;">
                            <input type="checkbox" name="backup_rotation_enabled" value="1" {{ $backupEnabled ? 'checked' : '' }}>
                            Enable scheduled backup rotation (master switch — individual servers still opt in from their own Backups tab)
                        </label>
                        <p class="text-muted small">When off, no automatic rotation runs for any server.</p>
                    </div>
                    <div class="form-group">
                        <label>Default backups to keep (per server)</label>
                        <input type="number" min="1" max="500" class="form-control" name="backup_keep_default" value="{{ $backupKeep }}" placeholder="5">
                        <p class="text-muted small">Used when a server has no per-server retention set. Never exceeds the server's own backup limit.</p>
                    </div>
                    <div class="form-group">
                        <label>Default backup cadence</label>
                        <select class="form-control" name="backup_default_interval_hours">
                            @foreach ($backupPresets as $h)
                                <option value="{{ $h }}" {{ $backupDefaultInterval === (int) $h ? 'selected' : '' }}>{{ $intervalLabels[$h] ?? ('Every ' . $h . 'h') }}</option>
                            @endforeach
                        </select>
                        <p class="text-muted small">How often opted-in servers are backed up when they haven't picked their own cadence on their Backups tab. The scheduler checks every 15 minutes and backs up each server when its interval is due.</p>
                    </div>
                    <div class="form-group">
                        <label>Backup timezone</label>
                        <select class="form-control" name="backup_timezone">
                            <option value="">Use panel timezone ({{ $appTz }})</option>
                            @foreach (timezone_identifiers_list() as $tzId)
                                <option value="{{ $tzId }}" {{ $backupTz === $tzId ? 'selected' : '' }}>{{ $tzId }}</option>
                            @endforeach
                        </select>
                        <p class="text-muted small">Used for the timestamp in auto-backup names and for the "Run at" hour servers pick on their Backups tab. Stored backup times stay in UTC.</p>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">Save settings</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-xs-12 col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">About</h3></div>
            <div class="box-body">
                <p>Server owners and permitted sub-users browse, install, update, reconcile and remove mods from the <strong>Mod Manager</strong> tab inside each server.</p>
                <p style="color:#888;">This extension is a UI layer over the <code>pterodactyl-serversuite/serversuite</code> Composer package, which performs all search, dependency resolution, delivery and tracking.</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-xs-12 col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Egg Manager</h3></div>
            <div class="box-body">
                @if (request()->query('egg_imported'))
                    <div class="alert alert-success">Imported "{{ request()->query('egg_imported') }}". It now appears under its nest in the panel.</div>
                @endif
                @if (request()->query('egg_error'))
                    <div class="alert alert-danger">Egg Manager error: {{ request()->query('egg_error') }}</div>
                @endif
                @if (request()->has('eggs_updated'))
                    <div class="alert alert-success">Update all finished: {{ request()->query('eggs_updated') }} updated, {{ request()->query('eggs_skipped') }} skipped (no update URL), {{ request()->query('eggs_failed') }} failed.</div>
                @endif

                <p class="text-muted">Pick a nest, then an egg from the community catalog. The nest is created on the panel if it doesn't already exist, mirroring the source's nest &gt; egg structure. Anything not listed can still be imported from a URL below.</p>

                <h4>Add an egg from the catalog</h4>
                <form method="POST" action="/extensions/serversuite/admin/import-catalog">
                    @csrf
                    <div class="form-group">
                        <label>Nest (category)</label>
                        <select name="nest_name" id="ss-nest" class="form-control"></select>
                        <p class="text-muted small">Created on the panel automatically if it doesn't exist yet.</p>
                    </div>
                    <div class="form-group">
                        <label>Egg</label>
                        <select name="egg_url" id="ss-egg" class="form-control"></select>
                        <p class="text-muted small" id="ss-egg-status">Loading egg catalog&hellip;</p>
                    </div>
                    <button type="submit" class="btn btn-primary">Import egg</button>
                    <button type="button" class="btn btn-default" id="ss-egg-reload">Reload catalog</button>
                </form>

                <hr>

                <h4>Keep eggs up to date</h4>
                <p class="text-muted small">Refreshes every installed egg in place from its own stored source URL. Eggs without an update URL are skipped. This does not touch any server's files.</p>
                <form method="POST" action="/extensions/serversuite/admin/update-eggs" style="display:inline-block;">
                    @csrf
                    <button type="submit" class="btn btn-default">Update all installed eggs</button>
                </form>

                <hr>

                <h4>Import from URL (advanced)</h4>
                <form method="POST" action="/extensions/serversuite/admin/import-egg">
                    @csrf
                    <div class="form-group">
                        <label>Nest ID</label>
                        <input type="number" name="nest_id" class="form-control" value="1">
                        <p class="text-muted small">Minecraft is normally nest 1.</p>
                    </div>
                    <div class="form-group">
                        <label>Egg JSON URL</label>
                        <input type="text" name="egg_url" class="form-control" placeholder="https://.../egg.json">
                        <p class="text-muted small">Paste any PTDL_v1 or PTDL_v2 egg JSON URL; the panel importer validates it. If the export carries meta.update_url, "Update all" can refresh it later.</p>
                    </div>
                    <button type="submit" class="btn btn-primary">Import egg</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xs-12 col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">About the catalog</h3></div>
            <div class="box-body">
                <p>The nest and egg lists are read live from the community pelican-eggs index, so they cover every game and software category &mdash; not just Minecraft.</p>
                <p>Choosing an egg imports it into a nest named after its catalog category, creating that nest if needed. Once imported, "Update all installed eggs" refreshes it from source.</p>
                <p style="color:#888;">Loader switching for a Minecraft server still needs the matching loader egg present &mdash; import it here once and it's available to every server.</p>
            </div>
        </div>
    </div>
</div>

{{-- Games (#77): which game each egg runs; decides the tabs its servers get. --}}
@php
    $ssGameDetector = app(\ServerSuite\Services\GameDetectionService::class);
    $ssGameChoices = \ServerSuite\Services\GameDetectionService::choices();
    $ssGameOverrides = \ServerSuite\Services\GameDetectionService::overrides();
    $ssGameEggs = \Pterodactyl\Models\Egg::query()->with('nest')->orderBy('nest_id')->orderBy('name')->get();
    $ssEggServers = \Pterodactyl\Models\Server::query()->selectRaw('egg_id, count(*) as n')->groupBy('egg_id')->pluck('n', 'egg_id');
@endphp
<div class="row" id="games">
    <div class="col-xs-12 col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Games</h3></div>
            <div class="box-body">
                @if (request()->query('games_ok'))
                    <div class="alert alert-success">{{ request()->query('games_ok') }}</div>
                @endif
                <form method="POST" action="/extensions/serversuite/admin/egg-games">
                    @csrf
                    <table class="table table-condensed">
                        <thead><tr><th>Egg</th><th>Servers</th><th>Detected</th><th style="width:220px;">Game</th></tr></thead>
                        <tbody>
                        @foreach ($ssGameEggs as $egg)
                            @php $ssAuto = $ssGameDetector->forEgg($egg); $ssPick = $ssGameOverrides[$egg->id] ?? ''; @endphp
                            <tr>
                                <td>{{ $egg->name }} <span class="text-muted small">({{ $egg->nest->name ?? 'no nest' }})</span></td>
                                <td>{{ $ssEggServers[$egg->id] ?? 0 }}</td>
                                <td><span title="Detected from: {{ $ssAuto->signal }}">{{ $ssAuto->name }}</span></td>
                                <td>
                                    <select name="game[{{ $egg->id }}]" class="form-control input-sm">
                                        <option value="" @if ($ssPick === '') selected @endif>Use detected</option>
                                        @foreach ($ssGameChoices as $key => $label)
                                            <option value="{{ $key }}" @if ($ssPick === $key) selected @endif>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">Save games</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xs-12 col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">About games</h3></div>
            <div class="box-body">
                <p>Minecraft servers get every Server Suite tab. Rust, Valheim and other games get Backups, Subdomains and Notifications, plus what has been built for that game.</p>
                <p>Version and loader switches, resets and modpacks are refused on anything that isn't Minecraft, so a plan sold as one game can't be turned into a Minecraft server.</p>
                <p>Rust and Valheim are found by the Steam app id in the egg's <code>SRCDS_APPID</code>; Minecraft by the egg's nest, name, image or startup command. Set the game here if detection gets an egg wrong.</p>
            </div>
        </div>
    </div>
</div>

{{-- Subdomains (#14): domains the admin offers + per-server allowances. --}}
@php
    $ssDomains = class_exists(\ServerSuite\Models\SubdomainDomain::class)
        ? \ServerSuite\Models\SubdomainDomain::query()->orderBy('zone')->get() : collect();
    $ssDomainUse = class_exists(\ServerSuite\Models\Subdomain::class)
        ? \ServerSuite\Models\Subdomain::query()->selectRaw('domain_id, count(*) as n')->groupBy('domain_id')->pluck('n', 'domain_id') : collect();
    $ssServers = \Pterodactyl\Models\Server::query()->orderBy('name')->get(['uuid', 'name']);
    $ssAllow = \ServerSuite\Models\ModManagerSetting::query()->pluck('subdomain_limit', 'server_uuid');
    $ssUsed = \ServerSuite\Models\Subdomain::query()->selectRaw('server_uuid, count(*) as n')->groupBy('server_uuid')->pluck('n', 'server_uuid');
@endphp
<div class="row" id="subdomains">
    <div class="col-xs-12 col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Subdomains (Cloudflare)</h3></div>
            <div class="box-body">
                @if (request()->query('subdomains_ok'))
                    <div class="alert alert-success">{{ request()->query('subdomains_ok') }}</div>
                @endif
                @if (request()->query('subdomains_error'))
                    <div class="alert alert-danger">{{ request()->query('subdomains_error') }}</div>
                @endif

                <h4>Domains offered to servers</h4>
                <table class="table table-condensed">
                    <thead><tr><th>Domain</th><th>Subdomains</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($ssDomains as $d)
                        <tr>
                            <td><code>{{ $d->zone }}</code></td>
                            <td>{{ $ssDomainUse[$d->id] ?? 0 }}</td>
                            <td>{!! $d->enabled ? '<span class="label label-success">offered</span>' : '<span class="label label-default">disabled</span>' !!}</td>
                            <td class="text-right">
                                <form method="POST" action="/extensions/serversuite/admin/domains/{{ $d->id }}/toggle" style="display:inline-block;">
                                    @csrf
                                    <button class="btn btn-xs btn-default">{{ $d->enabled ? 'Disable' : 'Enable' }}</button>
                                </form>
                                <form method="POST" action="/extensions/serversuite/admin/domains/{{ $d->id }}/remove" style="display:inline-block;"
                                      onsubmit="return confirm('Remove this domain and its stored token?');">
                                    @csrf
                                    <button class="btn btn-xs btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">No domains yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>

                <form method="POST" action="/extensions/serversuite/admin/domains" class="form-inline" autocomplete="off">
                    @csrf
                    <input type="text" name="zone" class="form-control" placeholder="example.com" required>
                    <input type="password" name="token" class="form-control" placeholder="Cloudflare API token" autocomplete="new-password" required>
                    <button type="submit" class="btn btn-primary">Add domain</button>
                </form>
                <p class="text-muted small" style="margin-top:6px;">
                    The token is checked with Cloudflare, stored encrypted, and never shown again. Use a token with
                    <strong>Zone &rarr; DNS &rarr; Edit</strong> for that domain only, never the Global API Key.
                    Disabling a domain stops new claims; existing subdomains keep working.
                </p>

                <hr>
                <h4>Allowance per server</h4>
                <p class="text-muted small">How many subdomains each server may hold, on your domains or its own (0 = none). Subdomains are "bought" outside the panel: take payment, then raise the allowance here.</p>
                <form method="POST" action="/extensions/serversuite/admin/subdomain-allowances">
                    @csrf
                    <table class="table table-condensed">
                        <thead><tr><th>Server</th><th>In use</th><th style="width:120px;">Allowance</th></tr></thead>
                        <tbody>
                        @foreach ($ssServers as $srv)
                            <tr>
                                <td>{{ $srv->name }} <span class="text-muted small"><code>{{ \Illuminate\Support\Str::limit($srv->uuid, 8, '') }}</code></span></td>
                                <td>{{ $ssUsed[$srv->uuid] ?? 0 }}</td>
                                <td><input type="number" min="0" max="100" class="form-control input-sm"
                                           name="allowance[{{ $srv->uuid }}]" value="{{ (int) ($ssAllow[$srv->uuid] ?? 0) }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <button type="submit" class="btn btn-primary">Save allowances</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xs-12 col-md-4">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">About subdomains</h3></div>
            <div class="box-body">
                <p>Server owners claim <code>name.yourdomain</code> from their server's <strong>Subdomains</strong> tab, or use their own Cloudflare domain. Each claim creates an A record (the server's IP, not proxied). Minecraft servers also get a <code>_minecraft._tcp</code> SRV record carrying the port, so players connect with just the name; Rust, Valheim and other games don't look one up, so their players add the port.</p>
                <p>Records are removed when a server is deleted and re-pointed when its primary allocation changes.</p>
            </div>
        </div>
    </div>
</div>

{{-- Egg Manager: populate the nest -> egg cascade from the live catalog.
     Plain DOM building (no template literals, no Blueprint reserved tokens). --}}
<script>
(function () {
    var nestSel = document.getElementById('ss-nest');
    var eggSel  = document.getElementById('ss-egg');
    var status  = document.getElementById('ss-egg-status');
    var reload  = document.getElementById('ss-egg-reload');
    if (!nestSel || !eggSel) { return; }

    var tree = [];

    function clear(sel) { while (sel.firstChild) { sel.removeChild(sel.firstChild); } }

    function opt(value, text) {
        var o = document.createElement('option');
        o.value = value;
        o.textContent = text;
        return o;
    }

    function fillEggs() {
        clear(eggSel);
        var chosen = nestSel.value;
        for (var i = 0; i < tree.length; i++) {
            if (tree[i].name !== chosen) { continue; }
            var list = tree[i].eggs || [];
            for (var j = 0; j < list.length; j++) {
                eggSel.appendChild(opt(list[j].url, list[j].name));
            }
            break;
        }
    }

    function fillNests() {
        clear(nestSel);
        for (var i = 0; i < tree.length; i++) {
            var count = (tree[i].eggs || []).length;
            nestSel.appendChild(opt(tree[i].name, tree[i].name + ' (' + count + ')'));
        }
        fillEggs();
    }

    function load(refresh) {
        if (status) { status.textContent = 'Loading egg catalog...'; }
        var url = '/extensions/serversuite/admin/egg-catalog' + (refresh ? '?refresh=1' : '');
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                tree = (payload && payload.nests) ? payload.nests : [];
                if (!tree.length) {
                    if (status) {
                        status.textContent = (payload && payload.error)
                            ? ('Catalog unavailable: ' + payload.error)
                            : 'Catalog is empty.';
                    }
                    return;
                }
                fillNests();
                if (status) {
                    var when  = payload.fetched_at ? (' - updated ' + payload.fetched_at) : '';
                    var stale = payload.stale ? ' - showing last cached copy' : '';
                    status.textContent = tree.length + ' nests loaded' + when + stale;
                }
            })
            .catch(function () {
                if (status) { status.textContent = 'Could not load the egg catalog.'; }
            });
    }

    nestSel.addEventListener('change', fillEggs);
    if (reload) {
        reload.addEventListener('click', function (e) { e.preventDefault(); load(true); });
    }
    load(false);
})();
</script>

@php
    $ssNotifier = app(\ServerSuite\Services\Notifier::class);
    $ssNotify = $ssNotifier->defaults();
@endphp
<div class="row" id="notifications">
    <div class="col-xs-12 col-md-8">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Default notifications (Discord, ntfy)</h3></div>
            <div class="box-body">
                @if (request()->query('notify_ok'))
                    <div class="alert alert-success">{{ request()->query('notify_ok') }}</div>
                @endif
                @if (request()->query('notify_error'))
                    <div class="alert alert-danger">{{ request()->query('notify_error') }}</div>
                @endif
                <p class="text-muted small">Where Server Suite events go for servers that haven't set up their own (on their Notifications tab). Unlike owners' targets, these may point at your own network. Stored encrypted; never shown again.</p>
                <table class="table table-condensed">
                    <tbody>
                    @forelse ($ssNotify['targets'] as $i => $t)
                        <tr>
                            <td><code>{{ \ServerSuite\Services\Notifier::label($t) }}</code></td>
                            <td class="text-right">
                                <form method="POST" action="/extensions/serversuite/admin/notifications" style="display:inline-block;">
                                    @csrf
                                    <input type="hidden" name="action" value="remove"><input type="hidden" name="index" value="{{ $i }}">
                                    <button class="btn btn-xs btn-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="text-muted">No default targets.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <form method="POST" action="/extensions/serversuite/admin/notifications" class="form-inline" autocomplete="off">
                    @csrf
                    <input type="hidden" name="action" value="add">
                    <select name="type" class="form-control"><option value="discord">Discord</option><option value="ntfy">ntfy</option></select>
                    <input type="url" name="url" class="form-control" placeholder="Discord webhook URL">
                    <input type="url" name="server" class="form-control" placeholder="ntfy server (https://ntfy.sh)">
                    <input type="text" name="topic" class="form-control" placeholder="ntfy topic">
                    <input type="password" name="token" class="form-control" placeholder="ntfy token (optional)" autocomplete="new-password">
                    <button type="submit" class="btn btn-primary">Add</button>
                </form>
                <hr>
                <form method="POST" action="/extensions/serversuite/admin/notifications">
                    @csrf
                    <input type="hidden" name="action" value="events">
                    @foreach (\ServerSuite\Services\Notifier::EVENTS as $key => $label)
                        <div class="checkbox"><label><input type="checkbox" name="event_{{ $key }}" value="1" @checked($ssNotify['events'][$key] ?? true)> {{ $label }}</label></div>
                    @endforeach
                    <button type="submit" class="btn btn-default">Save events</button>
                </form>
                <form method="POST" action="/extensions/serversuite/admin/notifications" style="margin-top:8px;">
                    @csrf
                    <input type="hidden" name="action" value="test">
                    <button type="submit" class="btn btn-default">Send a test</button>
                </form>
                <p class="text-muted small" style="margin-top:8px;">Needs the panel's queue worker and scheduler. Repeats are held back: a day for the same updates, six hours for a failing backup, at most 30 messages a server an hour.</p>
            </div>
        </div>
    </div>
</div>
