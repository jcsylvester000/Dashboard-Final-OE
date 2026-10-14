@extends('client.layout')
@php
    /** @var \App\Models\Invoice $invoice */
    $money = fn (int $minor) => \App\Domain\Billing\Money::formatForDocument($minor, $invoice->currency);
    $balance = $invoice->balanceMinor();
@endphp
@section('title', 'Invoice '.$invoice->displayNumber())
@section('content')
    <section class="card">
        <div class="bar"></div>
        @if ($logo)<img src="{{ $logo }}" alt="{{ $agency }}" style="max-height: 40px; max-width: 180px; margin-bottom: 8px">@endif
        <div class="brand">{{ $agency }} · invoice for {{ $invoice->bill_to['name'] ?? $invoice->workspace->name }}</div>
        <div class="row" style="align-items: end">
            <div>
                <h1>Invoice {{ $invoice->displayNumber() }}</h1>
                <p class="meta">Issued {{ $invoice->issue_date?->format('M j, Y') }} · due {{ $invoice->due_date?->format('M j, Y') }}
                    @if ($invoice->service_label) · {{ $invoice->service_label }}@endif</p>
            </div>
            <div style="text-align: right">
                @if ($invoice->status === \App\Models\Invoice::PAID)
                    <div class="ok">Paid in full. Thank you!</div>
                @else
                    <div class="meta">Balance due</div>
                    <div class="big {{ $invoice->daysOverdue() > 0 ? 'late' : '' }}">{{ $money($balance) }}</div>
                @endif
            </div>
        </div>
        <p style="margin: 14px 0 0"><a class="btn" href="{{ route('invoice-link.pdf', $invoice->share_token) }}">Download PDF</a></p>
    </section>

    <section class="card scroll">
        <h2>Details</h2>
        <table>
            <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
            <tbody>
            @foreach ($invoice->items as $item)
                <tr><td>{{ $item->description }}</td><td class="r">@if ($item->type !== 'discount'){{ $item->quantity }}{{ $item->minutes !== null ? ' h' : '' }}@endif</td><td class="r">{{ $money($item->amount_minor) }}</td></tr>
            @endforeach
            <tr><td></td><td class="r meta">Subtotal</td><td class="r">{{ $money($invoice->subtotal_minor) }}</td></tr>
            <tr><td></td><td class="r meta">{{ $taxLabel }}</td><td class="r">{{ $money($invoice->tax_minor) }}</td></tr>
            <tr><td></td><td class="r"><strong>Total</strong></td><td class="r"><strong>{{ $money($invoice->total_minor) }}</strong></td></tr>
            @if ($invoice->paid_minor + $invoice->credited_minor > 0)
                <tr><td></td><td class="r meta">Paid / credited</td><td class="r">-{{ $money($invoice->paid_minor + $invoice->credited_minor) }}</td></tr>
                <tr><td></td><td class="r"><strong>Balance due</strong></td><td class="r"><strong>{{ $money($balance) }}</strong></td></tr>
            @endif
            </tbody>
        </table>
    </section>

    @if ($paymentInstructions && $balance > 0)
        <section class="card">
            <h2>How to pay</h2>
            <div class="pre">{{ $paymentInstructions }}</div>
            <p class="meta" style="margin-top: 10px">Please use {{ $invoice->displayNumber() }} as the payment reference.</p>
        </section>
    @endif
@endsection
