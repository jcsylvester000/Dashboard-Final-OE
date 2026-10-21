{{-- E-52: a document generated from a template (logo, brand bar, page rules from E-43..E-48). --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    @include('pdf._style')
    <style>
        .doc h1 { font-size: 18px; margin: 14px 0 6px; } .doc h2 { font-size: 14px; margin: 14px 0 4px; } .doc h3 { font-size: 12px; margin: 10px 0 4px; }
        .doc p, .doc li { line-height: 1.5; } .doc table { margin: 6px 0 10px; } .doc h1, .doc h2, .doc h3 { page-break-after: avoid; }
    </style>
</head>
<body>
<div class="footer">{{ $company['name'] }} · {{ $title }} · {{ $project->name }}</div>
<div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#1f2937' }}"></div>
<table class="head"><tr>
    <td><div class="muted">{{ $workspace->name }} · {{ $project->name }}</div><h1>{{ $title }}</h1></td>
    <td class="right">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif<div>{{ $company['name'] }}</div></td>
</tr></table>
<div class="doc">{!! $html !!}</div>
</body>
</html>
