@php
    /** @var array{title: string, client: string, period: string|null, ref: string} $meta */
    $hours = fn (int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $meta['title'] }} - {{ $meta['client'] }}</title>
    @include('pdf._style')
</head>
<body>
@if ($draft)<div class="watermark">DRAFT</div>@endif
<div class="footer">{{ $company['name'] }} · {{ $meta['title'] }} · {{ $meta['ref'] }}</div>
<div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#1f2937' }}"></div>

<table class="head">
    <tr>
        <td style="width: 60%">
            @if ($logo ?? null)<img src="{{ $logo }}" class="logo" alt="">@endif
            <h1>{{ $meta['title'] }}</h1>
            <div><strong>{{ $meta['client'] }}</strong></div>
            @if ($meta['period'])<div class="muted">{{ $meta['period'] }}</div>@endif
        </td>
        <td class="right">
            <div>{{ $company['name'] }}</div>
            <div class="muted">{{ $meta['ref'] }}</div>
            <div style="font-size: 16px; margin-top: 6px"><strong>{{ $hours($report['total_minutes']) }}</strong> hours</div>
        </td>
    </tr>
</table>

@if (count($report['departments']) > 1)
    {{-- E-50: hours by department as a bar chart (same numbers as the table below). --}}
    @php($maxDept = max(1, ...array_map(fn ($d) => $d['minutes'], $report['departments'])))
    <table class="keep" style="margin-bottom: 6px">
        <thead><tr><th colspan="3" class="section">Hours by department</th></tr></thead>
        <tbody>
            @foreach ($report['departments'] as $dept)
                <tr>
                    <td style="width: 26%; border: none">{{ $dept['name'] }}</td>
                    <td style="border: none"><div style="height: 10px; width: {{ max(0.5, round($dept['minutes'] / $maxDept * 100, 1)) }}%; background: {{ $company['brand_color'] ?? '#2563eb' }}"></div></td>
                    <td class="right" style="width: 12%; border: none">{{ $hours($dept['minutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['departments']))
    <table>
        <thead><tr><th colspan="3" class="section">Hours by department and person</th></tr><tr><th>Department</th><th>Who</th><th class="right" style="width: 15%">Hours</th></tr></thead>
        <tbody>
            @foreach ($report['departments'] as $dept)
                @foreach ($dept['people'] as $i => $person)
                    <tr>
                        <td>@if ($i === 0)<strong>{{ $dept['name'] }}</strong> <span class="muted">({{ $hours($dept['minutes']) }})</span>@endif</td>
                        <td>{{ $person['name'] }}</td>
                        <td class="right">{{ $hours($person['minutes']) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['tasks']))
    <table>
        <thead><tr><th colspan="5" class="section">Tasks worked on</th></tr><tr><th>Task</th><th style="width: 16%">Department</th><th style="width: 14%">Status</th><th style="width: 22%">Who</th><th class="right" style="width: 10%">Hours</th></tr></thead>
        <tbody>
            @foreach ($report['tasks'] as $task)
                <tr>
                    <td>{{ $task['title'] }}@if ($task['completed_on'])<div class="small muted">Completed {{ \Carbon\CarbonImmutable::parse($task['completed_on'])->format('M j') }}</div>@endif</td>
                    <td>{{ $task['department'] }}</td>
                    <td>{{ $task['status'] ?? '—' }}</td>
                    <td class="small">@foreach ($task['people'] as $p){{ $p['name'] }} ({{ $hours($p['minutes']) }})@if (! $loop->last), @endif @endforeach</td>
                    <td class="right">{{ $hours($task['minutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['marketing']))
    <table>
        <thead><tr><th colspan="5" class="section">Marketing</th></tr><tr><th>Work</th><th>Campaign</th><th>Channel</th><th>Deliverable</th><th class="right">Hours</th></tr></thead>
        <tbody>
            @foreach ($report['marketing'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td>{{ $row['campaign'] ?? '—' }}</td>
                    <td>{{ $row['channel'] ?? '—' }}</td>
                    <td>{{ $row['deliverable_type'] ?? '—' }}</td>
                    <td class="right">{{ $hours($row['minutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['seo']))
    <table>
        <thead><tr><th colspan="5" class="section">SEO</th></tr><tr><th>Work</th><th>Page</th><th>Keyword</th><th>Type</th><th class="right">Hours</th></tr></thead>
        <tbody>
            @foreach ($report['seo'] as $row)
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td class="small">{{ $row['target_url'] ?? '—' }}</td>
                    <td>{{ $row['keyword'] ?? '—' }}</td>
                    <td>{{ $row['work_type'] ?? '—' }}</td>
                    <td class="right">{{ $hours($row['minutes']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($report['completed_without_time']))
    <table>
        <thead><tr><th colspan="2" class="section">Also completed this period</th></tr></thead>
        <tbody>
            @foreach ($report['completed_without_time'] as $t)
                <tr><td>{{ $t['title'] }}</td><td style="width: 20%">{{ $t['department'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($report['total_minutes'] === 0 && ! count($report['completed_without_time']))
    <p class="muted" style="margin-top: 20px">No billed time or completed tasks in this period.</p>
@endif
@if (count($report['sources'] ?? []))
    {{-- E-29: sources captured on the tasks in this report. --}}
    <table>
        <thead><tr><th colspan="3" class="section">Sources</th></tr><tr><th style="width: 28%">Task</th><th>Source</th><th style="width: 14%">Read on</th></tr></thead>
        <tbody>
            @foreach ($report['sources'] as $source)
                <tr>
                    <td>{{ $source['task'] }}</td>
                    <td>{{ $source['title'] ?? $source['url'] }}@if ($source['site'])<span class="muted"> · {{ $source['site'] }}</span>@endif<div class="muted small">{{ $source['url'] }}</div></td>
                    <td>{{ \Carbon\CarbonImmutable::parse($source['accessed_on'])->format('M j, Y') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
