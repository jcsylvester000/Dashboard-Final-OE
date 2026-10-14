{{-- E-27: sprint release notes as a branded PDF. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf._style')
    <style> .notes h1 { font-size: 18px; } .notes h2 { font-size: 13px; } .notes li { margin: 2px 0; } </style>
</head>
<body>
    <div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#2563eb' }}"></div>
    <table class="head"><tr>
        <td><div class="muted">{{ $company['name'] ?? '' }} · {{ $workspace->name }}{{ $sprint->closed_at ? ' · '.$sprint->closed_at->format('M j, Y') : '' }}</div></td>
        <td class="right">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
    </tr></table>
    <div class="notes">{!! $html !!}</div>
</body>
</html>
