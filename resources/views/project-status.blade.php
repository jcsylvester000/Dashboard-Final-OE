<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $project->name }} · status</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root { color-scheme: light dark; --bg: #f6f6f5; --card: #fff; --text: #171717; --muted: #6b7280; --line: #e5e7eb; --bar: #10b981; --late: #e11d48; --soon: #b45309; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f0f0f; --card: #1a1a1a; --text: #f5f5f5; --muted: #a3a3a3; --line: #2a2a2a; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; padding: 24px 16px; }
        main { max-width: 720px; margin: 0 auto; display: grid; gap: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .brand { font-size: 13px; color: var(--muted); }
        h1 { font-size: 22px; margin: 4px 0; } h2 { font-size: 15px; margin: 0 0 10px; }
        .meta { color: var(--muted); font-size: 13px; margin: 0; }
        .bar { height: 10px; background: var(--line); border-radius: 99px; overflow: hidden; margin: 12px 0 6px; }
        .bar > span { display: block; height: 100%; background: var(--bar); }
        ul { list-style: none; margin: 0; padding: 0; }
        li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--line); font-size: 14px; }
        li:first-child { border-top: 0; }
        .when { color: var(--muted); white-space: nowrap; }
        .late { color: var(--late); font-weight: 600; } .done { color: var(--bar); font-weight: 600; }
        .empty { color: var(--muted); font-size: 14px; }
        .foot { color: var(--muted); font-size: 12px; text-align: center; }
    </style>
</head>
<body>
<main>
    <section class="card">
        @if ($logo ?? null)<img src="{{ $logo }}" alt="{{ $agency ?? '' }}" style="max-height: 40px; max-width: 180px; margin-bottom: 8px">@endif
        <div class="brand">{{ $client }} · project status</div>
        <h1>{{ $project->name }}</h1>
        <p class="meta">
            @if ($project->start_on){{ $project->start_on->format('M j, Y') }}@endif
            @if ($project->due_on) – {{ $project->due_on->format('M j, Y') }}@endif
            · updated {{ $project->updated_at?->timezone(config('app.timezone'))->format('M j, Y') }}
        </p>
        <div class="bar" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100"><span style="width: {{ $percent }}%"></span></div>
        <p class="meta">{{ $percent }}% complete · {{ $done }} of {{ $total }} items done</p>
    </section>

    <section class="card">
        <h2>Milestones</h2>
        @if ($milestones->isEmpty())
            <p class="empty">No milestones set yet.</p>
        @else
            <ul>
                @foreach ($milestones as $m)
                    <li>
                        <span>◆ {{ $m->title }}</span>
                        @if ($m->completed_at)
                            <span class="done">Done</span>
                        @elseif ($m->due_on && $m->due_on->toDateString() < $today)
                            @if ($detailed)
                                <span class="late">Was due {{ $m->due_on->format('M j') }}</span>
                            @else
                                <span class="when">Date being updated</span>
                            @endif
                        @else
                            <span class="when">{{ $m->due_on?->format('M j, Y') ?? 'Date to be set' }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <h2>Coming up (next 2 weeks)</h2>
        @if ($upcoming->isEmpty())
            <p class="empty">Nothing due in the next two weeks.</p>
        @else
            <ul>
                @foreach ($upcoming as $t)
                    <li>
                        <span>{{ $t->title }}</span>
                        @if ($t->due_on->toDateString() < $today && ! $detailed)
                            <span class="when">{{ $stateLabel($t) }}</span>
                        @else
                            <span class="{{ $t->due_on->toDateString() < $today ? 'late' : 'when' }}">{{ $stateLabel($t) }} · {{ $t->due_on->toDateString() < $today ? 'was due' : 'due' }} {{ $t->due_on->format('M j') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <h2>Finished recently</h2>
        @if ($recent->isEmpty())
            <p class="empty">Nothing finished in the last two weeks.</p>
        @else
            <ul>
                @foreach ($recent as $t)
                    <li><span>{{ $t->title }}</span><span class="when">{{ $t->completed_at->timezone(config('app.timezone'))->format('M j') }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    <p class="foot">Shared by your agency. This page updates automatically; there's nothing to log in to.</p>
</main>
</body>
</html>
