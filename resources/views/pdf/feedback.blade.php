{{-- E-09: one version's review as a printable sheet: image with pins, then the comments. --}}
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('pdf._style')
    <style>
        .stage { position: relative; margin: 8px 0 14px; }
        .stage img { position: absolute; top: 0; left: 0; }
        .pin { display: inline-block; width: 18px; height: 18px; line-height: 18px; border-radius: 9px; color: #fff; text-align: center; font-weight: bold; font-size: 9px; }
        .client { font-size: 8px; text-transform: uppercase; color: #2563eb; border: 1px solid #2563eb; border-radius: 3px; padding: 0 3px; }
        .reply { color: #4b5563; margin-top: 3px; padding-left: 8px; border-left: 2px solid #e5e7eb; }
    </style>
</head>
<body>
    <div class="brand-bar" style="background: {{ $company['brand_color'] ?? '#2563eb' }}"></div>
    <table class="head"><tr>
        <td>
            <h1>Feedback: {{ $attachment->original_name }} (v{{ $attachment->version }})</h1>
            <div class="muted">
                @if ($task instanceof \App\Models\Task){{ $task->title }} · @endif
                {{ $threads->count() }} comment{{ $threads->count() === 1 ? '' : 's' }} ·
                {{ $threads->whereNull('resolved_at')->count() }} open ·
                @if ($attachment->review_status === 'approved') Approved{{ $attachment->client_decision_by ? ' by '.$attachment->client_decision_by.' (client)' : '' }} ·
                @elseif ($attachment->review_status === 'changes') Changes requested{{ $attachment->client_decision_by ? ' by '.$attachment->client_decision_by.' (client)' : '' }} ·
                @endif
                {{ $company['name'] ?? '' }}, {{ now()->format('M j, Y') }}
            </div>
        </td>
        <td class="right">@if ($logo)<img class="logo" src="{{ $logo }}" alt="">@endif</td>
    </tr></table>

    @if ($image)
        <div class="stage keep" style="width: {{ $width }}px; height: {{ $height }}px;">
            <img src="{{ $image }}" width="{{ $width }}" height="{{ $height }}" alt="">
            <img src="{{ $overlay }}" width="{{ $width }}" height="{{ $height }}" alt="">
        </div>
    @else
        <p class="muted">The image itself isn't stored here, so only the comments are listed.</p>
    @endif

    <table>
        <thead><tr><th style="width: 34px">#</th><th>Comment</th><th style="width: 110px">By</th><th style="width: 90px">Status</th></tr></thead>
        <tbody>
            @forelse ($threads as $t)
                <tr>
                    <td><span class="pin" style="background: {{ $t->color ?? \App\Models\Annotation::COLORS[0] }}">{{ $t->number }}</span></td>
                    <td>
                        {{ $t->body }}
                        @if ($t->x === null)<span class="muted small">(whole file)</span>@endif
                        @foreach ($t->replies as $r)
                            <div class="reply">{{ $r->client_name ?? ($r->author->name ?? 'Former member') }}: {{ $r->body }}</div>
                        @endforeach
                    </td>
                    <td>
                        {{ $t->client_name ?? ($t->author->name ?? 'Former member') }}
                        @if ($t->client_name)<span class="client">Client</span>@endif
                        <div class="muted small">{{ $t->created_at?->format('M j, g:i A') }}</div>
                    </td>
                    <td>
                        @if ($t->resolved_at)
                            Resolved {{ $t->resolved_at->format('M j') }}
                            <div class="muted small">by {{ $t->resolver->name ?? 'a team member' }}</div>
                        @else
                            Open
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No comments on this version.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
