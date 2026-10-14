@php
    /** @var array<string, mixed> $s  StatementService::build() */
    $money = fn (int $minor) => \App\Domain\Billing\Money::formatForDocument($minor, $s['currency']);
    $d = fn (?string $date) => $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '';
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Statement of account · {{ $client }}</title>
    @include('pdf._style')
</head>
<body>
<div class="footer">{{ $company['name'] }} · Statement of account · {{ $client }}</div>
<div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#1f2937' }}"></div>

<table class="head">
    <tr>
        <td style="width: 55%">
            @if ($logo ?? null)<img src="{{ $logo }}" class="logo" alt="">@endif
            <h1>{{ $company['legal_name'] ?? $company['name'] }}</h1>
            @if ($company['address'] ?? null)<div class="muted" style="white-space: pre-line">{{ $company['address'] }}</div>@endif
            @if ($company['tax_id'] ?? null)<div class="muted">TIN {{ $company['tax_id'] }}</div>@endif
            @if ($company['contact'] ?? null)<div class="muted">{{ $company['contact'] }}</div>@endif
        </td>
        <td class="right">
            <h1>Statement of account</h1>
            <div><strong>{{ $client }}</strong></div>
            <div>{{ $d($s['from']) }} – {{ $d($s['to']) }}</div>
            <div class="muted">Amounts in {{ $s['currency'] }}</div>
        </td>
    </tr>
</table>

<table class="totals keep" style="margin-top: 10px">
    <tr><td style="width: 64%"></td><td class="right muted">Opening balance</td><td class="right" style="width: 18%">{{ $money($s['opening_minor']) }}</td></tr>
    <tr><td></td><td class="right muted">Invoiced</td><td class="right">{{ $money($s['invoiced_minor']) }}</td></tr>
    <tr><td></td><td class="right muted">Payments and credits</td><td class="right">-{{ $money($s['received_minor']) }}</td></tr>
    <tr class="grand"><td></td><td class="right">{{ $s['closing_minor'] < 0 ? 'Credit in your favour' : 'Balance due' }}</td><td class="right">{{ $money(abs($s['closing_minor'])) }}</td></tr>
</table>

<table style="margin-top: 8px">
    <thead>
        <tr><th colspan="5" class="section">Activity</th></tr>
        <tr><th style="width: 14%">Date</th><th>Details</th><th class="right" style="width: 15%">Charges</th><th class="right" style="width: 15%">Payments</th><th class="right" style="width: 16%">Balance</th></tr>
    </thead>
    <tbody>
        <tr><td>{{ $d($s['from']) }}</td><td class="muted">Opening balance</td><td></td><td></td><td class="right">{{ $money($s['opening_minor']) }}</td></tr>
        @forelse ($s['lines'] as $l)
            <tr>
                <td>{{ $d($l['date']) }}</td>
                <td>{{ $l['description'] }}@if ($l['kind'] !== 'payment') · {{ $l['ref'] }}@elseif ($l['ref']) <span class="muted">· ref {{ $l['ref'] }}</span>@endif</td>
                <td class="right">{{ $l['debit_minor'] ? $money($l['debit_minor']) : '' }}</td>
                <td class="right">{{ $l['credit_minor'] ? $money($l['credit_minor']) : '' }}</td>
                <td class="right">{{ $money($l['balance_minor']) }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="muted">No activity in this period.</td></tr>
        @endforelse
    </tbody>
</table>

@if ($s['open'] !== [])
<table style="margin-top: 8px">
    <thead>
        <tr><th colspan="5" class="section">Open invoices</th></tr>
        <tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="right">Days overdue</th><th class="right">Balance</th></tr>
    </thead>
    <tbody>
        @foreach ($s['open'] as $o)
            <tr><td>{{ $o['number'] }}</td><td>{{ $d($o['issue_date']) }}</td><td>{{ $d($o['due_date']) }}</td><td class="right">{{ $o['days_overdue'] ?: '' }}</td><td class="right">{{ $money($o['balance_minor']) }}</td></tr>
        @endforeach
    </tbody>
</table>
@endif

<table class="keep" style="margin-top: 10px">
    <thead><tr><th class="right">Current</th><th class="right">1–30 days</th><th class="right">31–60</th><th class="right">61–90</th><th class="right">Over 90</th><th class="right">Total open</th></tr></thead>
    <tbody><tr>
        @foreach (['current', '1_30', '31_60', '61_90', '90_plus', 'total'] as $b)<td class="right">{{ $money($s['aging'][$b]) }}</td>@endforeach
    </tr></tbody>
</table>
@if ($s['unused_credit_minor'] > 0)
    <p class="small muted">Unused credit of {{ $money($s['unused_credit_minor']) }} will be applied to your next invoice.</p>
@endif

@if ($paymentInstructions)
    <div class="keep">
    <h2>How to pay</h2>
    <div class="box" style="white-space: pre-line">{{ $paymentInstructions }}</div>
    </div>
@endif
</body>
</html>
