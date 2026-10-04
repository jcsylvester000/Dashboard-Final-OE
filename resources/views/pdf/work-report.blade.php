@php
    /** @var \App\Models\Invoice $invoice */
    $hours = fn (int $m) => sprintf('%d:%02d', intdiv($m, 60), $m % 60);
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Work report {{ $invoice->displayNumber() }}</title>
    @include('pdf._style')
</head>
<body>
@if ($draft)<div class="watermark">DRAFT</div>@endif
<div class="footer">{{ $company['name'] }} · Work report for {{ $invoice->displayNumber() }}</div>

<table class="head">
    <tr>
        <td style="width: 60%">
            <h1>Work report</h1>
            <div><strong>{{ $invoice->bill_to['name'] ?? $invoice->workspace->name }}</strong></div>
            @if ($invoice->period_start && $invoice->period_end)
                <div class="muted">{{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}</div>
            @endif
        </td>
        <td class="right">
            <div>{{ $company['name'] }}</div>
            <div class="muted">For invoice {{ $invoice->displayNumber() }}</div>
            <div style="font-size: 16px; margin-top: 6px"><strong>{{ $hours($report['total_minutes']) }}</strong> hours</div>
        </td>
    </tr>
</table>

@if (count($report['departments']))
    <h2>Hours by department and person</h2>
    <table>
        <thead><tr><th>Department</th><th>Who</th><th class="right" style="width: 15%">Hours</th></tr></thead>
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
    <h2>Tasks worked on</h2>
    <table>
        <thead><tr><th>Task</th><th style="width: 16%">Department</th><th style="width: 14%">Status</th><th style="width: 22%">Who</th><th class="right" style="width: 10%">Hours</th></tr></thead>
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
    <h2>Marketing</h2>
    <table>
        <thead><tr><th>Work</th><th>Campaign</th><th>Channel</th><th>Deliverable</th><th class="right">Hours</th></tr></thead>
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
    <h2>SEO</h2>
    <table>
        <thead><tr><th>Work</th><th>Page</th><th>Keyword</th><th>Type</th><th class="right">Hours</th></tr></thead>
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
    <h2>Also completed this period</h2>
    <table>
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
</body>
</html>
