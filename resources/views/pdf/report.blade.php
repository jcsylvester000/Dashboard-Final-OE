{{-- E-49: any report as a branded PDF: title, filters, optional bar chart, table, totals. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf._style')
    <style>
        .bars td { border: none; padding: 2px 3px; }
        .bar { height: 9px; background: {{ $company['brand_color'] ?? '#2563eb' }}; }
        .bar.neg { background: #dc2626; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; border-top: 1px solid #1f2937; border-bottom: none; }
    </style>
</head>
<body>
    <div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#2563eb' }}"></div>
    <table class="head"><tr>
        <td>
            <h1>{{ $t->title }}</h1>
            <div class="muted">{{ $company['name'] ?? '' }} · Generated {{ now()->format('M j, Y H:i') }}</div>
        </td>
        <td class="right">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
    </tr></table>

    <div class="box" style="margin-top: 10px">
        <strong>Filters:</strong>
        @forelse ($t->filters as $label => $value)
            {{ $label }}: {{ $value }}@if (! $loop->last) · @endif
        @empty
            All data
        @endforelse
    </div>

    @if ($chart !== [])
        <h2>{{ $chartLabel }}</h2>
        <table class="bars keep">
            @foreach ($chart as $bar)
                <tr>
                    <td style="width: 28%">{{ \Illuminate\Support\Str::limit($bar['label'], 40) }}</td>
                    <td style="width: 57%"><div class="bar {{ $bar['neg'] ? 'neg' : '' }}" style="width: {{ max(0.5, $bar['pct']) }}%"></div></td>
                    <td class="num" style="width: 15%">{{ $bar['value'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Details</h2>
    @if ($t->rows === [])
        <p class="muted">No data for these filters.</p>
    @else
        <table>
            <thead><tr>
                @foreach ($t->columns as $c)
                    <th class="{{ $c['type'] === 'text' || $c['type'] === 'date' ? '' : 'num' }}">{{ $c['label'] }}@if ($c['type'] === 'money' && ($c['currency'] ?? null)) ({{ $c['currency'] }})@endif</th>
                @endforeach
            </tr></thead>
            <tbody>
                @foreach ($t->rows as $row)
                    <tr>
                        @foreach ($t->columns as $c)
                            <td class="{{ $c['type'] === 'text' || $c['type'] === 'date' ? '' : 'num' }}">{{ \App\Domain\Exports\ReportExporter::show($row[$c['key']] ?? null, $c['type']) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @if ($totals !== [])
                <tfoot><tr>
                    @foreach ($t->columns as $i => $c)
                        <td class="{{ $i === 0 ? '' : 'num' }}">{{ $i === 0 ? 'Total' : (array_key_exists($c['key'], $totals) ? \App\Domain\Exports\ReportExporter::show($totals[$c['key']], $c['type']) : '') }}</td>
                    @endforeach
                </tr></tfoot>
            @endif
        </table>
    @endif
</body>
</html>
