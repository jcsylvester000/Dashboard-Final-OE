@php
    /** @var \App\Models\Invoice $invoice */
    $money = fn (int $minor) => \App\Domain\Billing\Money::format($minor, $invoice->currency);
    $title = $invoice->kind === 'credit_note' ? 'Credit note' : 'Invoice';
@endphp
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }} {{ $invoice->displayNumber() }}</title>
    @include('pdf._style')
</head>
<body>
@if ($draft)<div class="watermark">DRAFT</div>@endif
<div class="footer">{{ $company['name'] }} · {{ $title }} {{ $invoice->displayNumber() }}</div>

<table class="head">
    <tr>
        <td style="width: 55%">
            <h1>{{ $company['name'] }}</h1>
            @if ($company['address'])<div class="muted" style="white-space: pre-line">{{ $company['address'] }}</div>@endif
            @if ($company['tax_id'])<div class="muted">TIN {{ $company['tax_id'] }}</div>@endif
            @if ($company['contact'])<div class="muted">{{ $company['contact'] }}</div>@endif
        </td>
        <td class="right">
            <h1>{{ $title }}</h1>
            <div><strong>{{ $invoice->displayNumber() }}</strong></div>
            @if ($invoice->issue_date)<div>Issued {{ $invoice->issue_date->format('M j, Y') }}</div>@endif
            @if ($invoice->due_date && $invoice->kind === 'invoice')<div>Due {{ $invoice->due_date->format('M j, Y') }}</div>@endif
            @if ($invoice->period_start && $invoice->period_end)
                <div class="muted">Period {{ $invoice->period_start->format('M j') }} – {{ $invoice->period_end->format('M j, Y') }}</div>
            @endif
        </td>
    </tr>
</table>

<h2>Bill to</h2>
@php($billTo = $invoice->bill_to ?? [])
<div><strong>{{ $billTo['name'] ?? $invoice->workspace->name }}</strong></div>
@if (! empty($billTo['address']))<div style="white-space: pre-line">{{ $billTo['address'] }}</div>@endif
@if (! empty($billTo['tax_id']))<div>TIN {{ $billTo['tax_id'] }}</div>@endif
@if (! empty($billTo['contact']))<div>Attn: {{ $billTo['contact'] }}</div>@endif

<h2>Details</h2>
<table>
    <thead>
        <tr>
            <th>Description</th>
            <th class="right" style="width: 12%">Qty</th>
            <th class="right" style="width: 18%">Rate</th>
            <th class="right" style="width: 18%">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($invoice->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ $item->quantity }}{{ $item->minutes !== null ? ' h' : '' }}</td>
                <td class="right">{{ $money($item->unit_minor) }}</td>
                <td class="right">{{ $money($item->amount_minor) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="totals" style="margin-top: 8px">
    <tr><td style="width: 64%"></td><td class="right muted">Subtotal</td><td class="right" style="width: 18%">{{ $money($invoice->subtotal_minor) }}</td></tr>
    <tr><td></td><td class="right muted">Tax ({{ sprintf('%d.%02d', intdiv($invoice->tax_rate_bp, 100), $invoice->tax_rate_bp % 100) }}%)</td><td class="right">{{ $money($invoice->tax_minor) }}</td></tr>
    <tr class="grand"><td></td><td class="right">Total</td><td class="right">{{ $money($invoice->total_minor) }}</td></tr>
</table>

@if ($invoice->notes)
    <h2>Notes</h2>
    <div style="white-space: pre-line">{{ $invoice->notes }}</div>
@endif

@if ($paymentInstructions && $invoice->kind === 'invoice')
    <h2>How to pay</h2>
    <div class="box" style="white-space: pre-line">{{ $paymentInstructions }}</div>
@endif

<p class="small muted" style="margin-top: 18px">
    A work report listing the tasks and hours behind this {{ strtolower($title) }} is provided separately.
</p>
</body>
</html>
