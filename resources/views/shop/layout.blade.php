<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payments Lab</title>
    <style>
        :root { --bg:#f6f7fb; --card:#fff; --ink:#1d2433; --muted:#6b7280; --line:#e5e7eb; --stripe:#635bff; --paypal:#ffc439; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, sans-serif; background:var(--bg); color:var(--ink); }
        header { background:var(--card); border-bottom:1px solid var(--line); padding:14px 20px; display:flex; gap:20px; align-items:center; }
        header a { color:var(--ink); text-decoration:none; font-weight:600; }
        main { max-width:960px; margin:24px auto; padding:0 16px; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
        .emoji { font-size:42px; }
        .price { font-size:22px; font-weight:700; margin:8px 0 16px; }
        button { width:100%; padding:10px; border:0; border-radius:8px; font-weight:600; cursor:pointer; margin-top:8px; font-size:15px; }
        button:disabled { opacity:.4; cursor:not-allowed; }
        .btn-stripe { background:var(--stripe); color:#fff; }
        .btn-paypal { background:var(--paypal); color:#003087; }
        .muted { color:var(--muted); }
        .notice { background:#fff8e1; border:1px solid #f3d27a; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; background:var(--card); }
        th, td { padding:10px; border-bottom:1px solid var(--line); text-align:left; }
        .badge { padding:2px 8px; border-radius:999px; font-size:13px; }
        .paid { background:#dcfce7; color:#166534; } .pending { background:#fef9c3; color:#854d0e; }
        .cancelled, .failed { background:#fee2e2; color:#991b1b; }
        pre { background:#0f172a; color:#e2e8f0; padding:16px; border-radius:8px; overflow:auto; font-size:12px; }
        .flash { background:#eef6ff; border:1px solid #bcd7fb; padding:12px 16px; border-radius:8px; margin-bottom:16px; }
        code { background:#eef; padding:1px 4px; border-radius:4px; }
    </style>
    @stack('head')
</head>
<body>
<header>
    <a href="{{ route('shop') }}">🧪 Payments Lab</a>
    <a href="{{ route('orders') }}">Orders</a>
    <span class="muted">Test mode only — no real money moves</span>
</header>
<main>
    @if(session('error'))
        <div class="notice">{{ session('error') }}</div>
    @endif
    @if(session('notice'))
        <div class="flash">{{ session('notice') }}</div>
    @endif
    @yield('content')
</main>
</body>
</html>
