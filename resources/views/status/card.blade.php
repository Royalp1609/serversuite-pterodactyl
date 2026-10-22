@php($s = $status)
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $s['fqdn'] }} · {{ $s['online'] ? 'online' : 'offline' }}</title>
<style>
    :root { --bg:#f8fafc; --card:#fff; --text:#0f172a; --muted:#64748b; --line:#e2e8f0; --on:#16a34a; --off:#94a3b8; }
    @media (prefers-color-scheme: dark) { :root { --bg:#0b1120; --card:#111827; --text:#e2e8f0; --muted:#94a3b8; --line:#1f2937; --on:#22c55e; --off:#64748b; } }
    * { box-sizing:border-box; }
    body { margin:0; font:15px/1.45 system-ui,-apple-system,"Segoe UI",sans-serif; color:var(--text); background:{{ $embed ? 'transparent' : 'var(--bg)' }}; }
    .wrap { {{ $embed ? '' : 'min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;' }} }
    .card { width:100%; max-width:30rem; background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; display:flex; gap:14px; align-items:flex-start; }
    .icon { width:64px; height:64px; border-radius:8px; flex:none; image-rendering:pixelated; background:var(--line); }
    .name { font-weight:700; font-size:1.05rem; word-break:break-all; }
    .state { display:inline-flex; align-items:center; gap:6px; font-size:.85rem; font-weight:600; }
    .dot { width:9px; height:9px; border-radius:50%; background:var(--off); }
    .on .dot { background:var(--on); }
    .motd { margin:.4rem 0 0; white-space:pre-line; color:var(--text); font-size:.9rem; }
    .meta { margin-top:.45rem; font-size:.8rem; color:var(--muted); }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        @if ($s['icon'])
            <img class="icon" src="{{ $s['icon'] }}" alt="">
        @else
            <div class="icon" aria-hidden="true"></div>
        @endif
        <div style="min-width:0;">
            <div class="name">{{ $s['fqdn'] }}</div>
            <div class="state {{ $s['online'] ? 'on' : '' }}"><span class="dot"></span>
                {{ $s['online'] ? 'Online' : 'Offline' }}@if ($s['online'] && $s['players'] !== null) · {{ $s['players'] }}/{{ $s['max'] }} players @endif
            </div>
            @if ($s['motd'] !== '')
                <p class="motd">{{ $s['motd'] }}</p>
            @endif
            <div class="meta">
                @if ($s['version'] !== '') {{ $s['version'] }} · @endif
                Checked {{ \Illuminate\Support\Carbon::parse($s['checked_at'])->diffForHumans() }}
            </div>
        </div>
    </div>
</div>
</body>
</html>
