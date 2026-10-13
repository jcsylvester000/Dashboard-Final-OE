<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $file->name }} · {{ config('app.name') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f5; --card: #fff; --text: #171717; --muted: #6b7280; --line: #e5e7eb; --btn: #171717; --btn-text: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #f5f5f5; --muted: #a3a3a3; --line: #2a2a2a; --btn: #f5f5f5; --btn-text: #111; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        .card { width: 100%; max-width: 560px; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 24px; }
        .brand { font-size: 13px; color: var(--muted); margin-bottom: 16px; }
        h1 { font-size: 18px; margin: 0 0 4px; word-break: break-word; }
        .meta { color: var(--muted); font-size: 13px; margin: 0 0 20px; }
        .preview { margin: 0 0 20px; border-radius: 10px; overflow: hidden; border: 1px solid var(--line); background: var(--bg); }
        .preview img { display: block; width: 100%; height: auto; max-height: 60vh; object-fit: contain; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        a.btn { display: inline-block; padding: 10px 16px; border-radius: 8px; background: var(--btn); color: var(--btn-text); text-decoration: none; font-weight: 600; font-size: 14px; }
        a.ghost { background: transparent; color: var(--text); border: 1px solid var(--line); }
        .foot { margin-top: 20px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">{{ config('app.name') }} · shared file</div>
    <h1>{{ $file->name }}</h1>
    <p class="meta">{{ $size }} · shared by {{ $from }}@if ($expires) · link expires {{ $expires->timezone(config('app.timezone'))->format('M j, Y') }}@endif</p>

    @if ($file->isImage())
        <div class="preview"><img src="{{ $fileUrl }}" alt="{{ $file->name }}"></div>
    @endif

    <div class="actions">
        <a class="btn" href="{{ $downloadUrl }}" rel="noopener">Download</a>
        @unless ($file->isImage())
            <a class="btn ghost" href="{{ $fileUrl }}" target="_blank" rel="noopener">Open in browser</a>
        @endunless
    </div>
    <p class="foot">This link was shared with you directly. Please don't forward it unless you were asked to.</p>
</main>
</body>
</html>
