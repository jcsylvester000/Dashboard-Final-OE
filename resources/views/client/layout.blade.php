<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title')</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f5; --card: #fff; --text: #171717; --muted: #6b7280; --line: #e5e7eb; --accent: {{ $brand ?? '#1f2937' }}; --ok: #059669; --late: #e11d48; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #f5f5f5; --muted: #a3a3a3; --line: #2a2a2a; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; padding: 24px 16px; }
        main { max-width: 760px; margin: 0 auto; display: grid; gap: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .bar { height: 4px; background: var(--accent); border-radius: 14px 14px 0 0; margin: -20px -20px 16px; }
        .brand { font-size: 13px; color: var(--muted); }
        h1 { font-size: 22px; margin: 4px 0; } h2 { font-size: 15px; margin: 0 0 10px; }
        .meta { color: var(--muted); font-size: 13px; margin: 0; }
        .row { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; font-size: 12px; color: var(--muted); font-weight: 500; padding: 6px 4px; border-bottom: 1px solid var(--line); }
        td { padding: 8px 4px; border-bottom: 1px solid var(--line); vertical-align: top; }
        .r { text-align: right; white-space: nowrap; }
        .big { font-size: 26px; font-weight: 700; }
        .ok { color: var(--ok); font-weight: 600; } .late { color: var(--late); font-weight: 600; }
        .btn { display: inline-block; background: var(--accent); color: #fff; text-decoration: none; padding: 9px 14px; border-radius: 9px; font-weight: 600; font-size: 14px; }
        .pre { white-space: pre-line; }
        .foot { color: var(--muted); font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<main>
    @yield('content')
    <p class="foot">Shared by {{ $agency }}. This page is private to you; please don't forward the link.</p>
</main>
</body>
</html>
