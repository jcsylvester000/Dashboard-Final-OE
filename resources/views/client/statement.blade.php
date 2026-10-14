@extends('client.layout')
@php
    $money = fn (int $minor) => \App\Domain\Billing\Money::formatForDocument($minor, $s['currency']);
    $d = fn (?string $date) => $date ? \Carbon\CarbonImmutable::parse($date)->format('M j, Y') : '';
@endphp
@section('title', 'Statement of account')
@section('content')
    <section class="card">
        <div class="bar"></div>
        @if ($logo)<img src="{{ $logo }}" alt="{{ $agency }}" style="max-height: 40px; max-width: 180px; margin-bottom: 8px">@endif
        <div class="brand">{{ $agency }} · statement of account</div>
        <div class="row" style="align-items: end">
            <div>
                <h1>{{ $client }}</h1>
                <p class="meta">{{ $d($s['from']) }} – {{ $d($s['to']) }} · {{ $s['currency'] }}</p>
            </div>
            <div style="text-align: right">
                <div class="meta">{{ $s['closing_minor'] < 0 ? 'Credit in your favour' : 'Balance due' }}</div>
                <div class="big">{{ $money(abs($s['closing_minor'])) }}</div>
            </div>
        </div>
        <p style="margin: 14px 0 0"><a class="btn" href="{{ route('statement-link.pdf', $token) }}">Download PDF</a></p>
    </section>

    @if ($s['open'] !== [])
        <section class="card scroll">
            <h2>Open invoices</h2>
            <table>
                <thead><tr><th>Invoice</th><th>Due</th><th class="r">Balance</th></tr></thead>
                <tbody>
                @foreach ($s['open'] as $o)
                    <tr><td>{{ $o['number'] }}</td><td class="{{ $o['days_overdue'] > 0 ? 'late' : '' }}">{{ $d($o['due_date']) }}@if ($o['days_overdue'] > 0) · {{ $o['days_overdue'] }} days overdue @endif</td><td class="r">{{ $money($o['balance_minor']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <section class="card scroll">
        <h2>Activity</h2>
        <table>
            <thead><tr><th>Date</th><th>Details</th><th class="r">Charges</th><th class="r">Payments</th><th class="r">Balance</th></tr></thead>
            <tbody>
            <tr><td>{{ $d($s['from']) }}</td><td class="meta">Opening balance</td><td></td><td></td><td class="r">{{ $money($s['opening_minor']) }}</td></tr>
            @foreach ($s['lines'] as $l)
                <tr><td>{{ $d($l['date']) }}</td><td>{{ $l['description'] }}@if ($l['kind'] !== 'payment') · {{ $l['ref'] }}@endif</td>
                    <td class="r">{{ $l['debit_minor'] ? $money($l['debit_minor']) : '' }}</td><td class="r">{{ $l['credit_minor'] ? $money($l['credit_minor']) : '' }}</td><td class="r">{{ $money($l['balance_minor']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </section>

    @if ($paymentInstructions && $s['closing_minor'] > 0)
        <section class="card"><h2>How to pay</h2><div class="pre">{{ $paymentInstructions }}</div></section>
    @endif
@endsection
