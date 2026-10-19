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
                        <label>Backup name timezone</label>
                        <select class="form-control" name="backup_timezone">
                            <option value="">Use panel timezone ({{ $appTz }})</option>
                            @foreach (timezone_identifiers_list() as $tzId)
                                <option value="{{ $tzId }}" {{ $backupTz === $tzId ? 'selected' : '' }}>{{ $tzId }}</option>
                            @endforeach
                        </select>
                        <p class="text-muted small">Only affects the timestamp shown in auto-backup names. Stored backup times stay in UTC.</p>
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
