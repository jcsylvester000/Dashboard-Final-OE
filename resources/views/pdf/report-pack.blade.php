@php
    $hm = fn (int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $pack->name }}</title>
    @include('pdf._style')
    <style> td.num, th.num { text-align: right; white-space: nowrap; } h2 { font-size: 13px; margin: 16px 0 4px; } </style>
</head>
<body>
<div class="footer">{{ $company['name'] }} · {{ $pack->name }} · {{ $workspace->name }}</div>
<div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#1f2937' }}"></div>
<table class="head"><tr>
    <td>
        <h1>{{ $pack->name }}</h1>
        <div><strong>{{ $workspace->name }}</strong></div>
        <div class="muted">{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</div>
    </td>
    <td class="right">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif<div>{{ $company['name'] }}</div></td>
</tr></table>

@if (in_array('hours', $sections, true) && $hours)
    <h2>Hours by department and person ({{ $hm($hours['total_minutes']) }} total)</h2>
    <table>
        <thead><tr><th>Department</th><th>Who</th><th class="num">Hours</th></tr></thead>
        <tbody>
        @forelse ($hours['departments'] as $d)
            @foreach ($d['people'] as $i => $p)
                <tr><td>@if ($i === 0)<strong>{{ $d['name'] }}</strong>@endif</td><td>{{ $p['name'] }}</td><td class="num">{{ $hm($p['minutes']) }}</td></tr>
            @endforeach
        @empty
            <tr><td colspan="3" class="muted">No time logged in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if (in_array('completed', $sections, true))
    <h2>Tasks completed ({{ count($completed) }})</h2>
    <table>
        <thead><tr><th style="width: 12%">Task</th><th>Title</th><th class="num" style="width: 12%">Done</th></tr></thead>
        <tbody>
        @forelse ($completed as $t)
            <tr><td>{{ $t['key'] }}</td><td>{{ $t['title'] }}</td><td class="num">{{ $t['on'] }}</td></tr>
        @empty
            <tr><td colspan="3" class="muted">Nothing completed in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if (in_array('estimates', $sections, true))
    <h2>Estimate vs actual (completed tasks with an estimate)</h2>
    <table>
        <thead><tr><th>Task</th><th class="num">Estimate</th><th class="num">Actual</th><th class="num">Difference</th></tr></thead>
        <tbody>
        @forelse (array_filter($completed, fn ($t) => $t['estimate'] > 0) as $t)
            <tr><td>{{ $t['key'] }} {{ $t['title'] }}</td><td class="num">{{ $hm($t['estimate']) }}</td><td class="num">{{ $hm($t['actual']) }}</td>
                <td class="num">{{ $t['actual'] >= $t['estimate'] ? '+' : '−' }}{{ $hm(abs($t['actual'] - $t['estimate'])) }}</td></tr>
        @empty
            <tr><td colspan="4" class="muted">No estimated tasks completed in this period.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if (in_array('open', $sections, true))
    <h2>Open work by status</h2>
    <table>
        <tbody>
        @forelse ($open as $name => $count)
            <tr><td>{{ $name }}</td><td class="num">{{ $count }}</td></tr>
        @empty
            <tr><td class="muted">No open tasks.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif

@if (in_array('overdue', $sections, true))
    <h2>Overdue ({{ count($overdue) }})</h2>
    <table>
        <tbody>
        @forelse ($overdue as $t)
            <tr><td style="width: 12%">{{ $t['key'] }}</td><td>{{ $t['title'] }}</td><td class="num">due {{ $t['due'] }}</td></tr>
        @empty
            <tr><td class="muted">Nothing overdue.</td></tr>
        @endforelse
        </tbody>
    </table>
@endif
</body>
</html>
