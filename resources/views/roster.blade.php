<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="60">
    <title>FootballLab DXB · Games</title>
    <style>
        :root { --bg:#f6f7f5; --card:#fff; --ink:#15201a; --muted:#5d6b62; --line:#e3e7e2; --accent:#0f7a3d; --full:#b4232c; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0f1411; --card:#18201b; --ink:#e8efe9; --muted:#9aa89f; --line:#26302a; --accent:#3ccf7a; --full:#ff6b72; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { max-width:560px; margin:0 auto; padding:20px 16px 48px; }
        h1 { font-size:22px; margin:0 0 4px; }
        .sub { color:var(--muted); margin:0 0 20px; font-size:13px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; margin-bottom:14px; }
        .when { font-weight:700; font-size:17px; }
        .meta { color:var(--muted); font-size:13px; margin:2px 0 12px; }
        .badge { display:inline-block; font-size:12px; font-weight:700; padding:3px 9px; border-radius:99px; background:color-mix(in srgb, var(--accent) 14%, transparent); color:var(--accent); }
        .badge.full { background:color-mix(in srgb, var(--full) 14%, transparent); color:var(--full); }
        ol { margin:12px 0 0; padding-left:26px; columns:2; column-gap:20px; }
        li { padding:2px 0; break-inside:avoid; }
        li.empty { color:var(--muted); }
        .cta { display:block; text-align:center; margin-top:18px; padding:13px; border-radius:12px; background:var(--accent); color:#fff; font-weight:700; text-decoration:none; }
        .empty-state { color:var(--muted); text-align:center; padding:30px 0; }
        @media (max-width:380px) { ol { columns:1; } }
    </style>
</head>
<body>
<main>
    <h1>⚽ FootballLab DXB</h1>
    <p class="sub">Live game lists · updates every minute · {{ now()->setTimezone(config('fbl.timezone'))->format('D j M, g:i A') }}</p>

    @forelse ($games as $row)
        @php($g = $row['game'])
        <section class="card">
            <div class="when">{{ $g->localStart()->format('l j M · g:i A') }}</div>
            <div class="meta">📍 {{ $g->venue }}@if($g->format) · {{ $g->format }}@endif · 💰 {{ (float) $g->price }} {{ $currency }}</div>
            @if ($row['left'] > 0)
                <span class="badge">{{ $row['left'] }} spot{{ $row['left'] === 1 ? '' : 's' }} left</span>
            @else
                <span class="badge full">Full · join the waitlist</span>
            @endif
            <ol>
                @for ($i = 0; $i < max($g->capacity, $row['names']->count()); $i++)
                    @if (isset($row['names'][$i]))
                        <li>{{ $row['names'][$i] }}</li>
                    @else
                        <li class="empty">open</li>
                    @endif
                @endfor
            </ol>
        </section>
    @empty
        <p class="empty-state">No games published yet. Check back soon.</p>
    @endforelse

    <a class="cta" href="{{ $bookUrl }}">Book a spot on WhatsApp</a>
</main>
</body>
</html>
