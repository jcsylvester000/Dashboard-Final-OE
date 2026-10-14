@extends('client.layout')

@section('title', 'Review: '.$attachment->original_name)

@section('content')
    <style>
        .stage { position: relative; display: inline-block; max-width: 100%; cursor: crosshair; }
        .stage img { display: block; max-width: 100%; height: auto; border-radius: 8px; }
        .pin { position: absolute; transform: translate(-50%, -50%); width: 24px; height: 24px; border-radius: 12px; background: var(--accent); color: #fff; font: 700 12px/24px system-ui; text-align: center; border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,.4); }
        .pin.new { background: #d55e00; }
        textarea, input[type=text] { width: 100%; font: inherit; padding: 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); color: var(--text); }
        .thread { border-top: 1px solid var(--line); padding: 10px 0; }
        .reply { margin: 6px 0 0 30px; color: var(--muted); }
        .btn.alt { background: transparent; color: var(--text); border: 1px solid var(--line); }
        .notice { background: #ecfdf5; color: #065f46; border-radius: 8px; padding: 8px 12px; }
        .err { color: var(--late); font-size: 13px; }
        button.btn { border: 0; cursor: pointer; }
    </style>

    <div class="card">
        <div class="bar"></div>
        <div class="row">
            <div>
                <p class="brand">{{ $agency }} · review</p>
                <h1>{{ $attachment->original_name }} <span class="meta">version {{ $attachment->version }}</span></h1>
                @if ($attachment->review_status === 'approved')
                    <p class="ok">Approved{{ $attachment->client_decision_by ? ' by '.$attachment->client_decision_by : '' }}</p>
                @elseif ($attachment->review_status === 'changes')
                    <p class="late">Changes requested{{ $attachment->client_decision_by ? ' by '.$attachment->client_decision_by : '' }}</p>
                @endif
            </div>
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 40px">@endif
        </div>
        @if (session('client_review_status'))
            <p class="notice" role="status">{{ session('client_review_status') }}</p>
        @endif
        @if (! $attachment->is_latest)
            <p class="late">A newer version has been shared since. Comments here still reach the team.</p>
        @endif
    </div>

    @if ($name === null)
        <div class="card">
            <h2>Before you comment</h2>
            <form method="post" action="{{ route('client-review.name', $token) }}">
                @csrf
                <label for="name" class="meta">Your name (shown with your comments)</label>
                <input id="name" type="text" name="name" required minlength="2" maxlength="80" autocomplete="name">
                @error('name')<p class="err">{{ $message }}</p>@enderror
                <p><button class="btn" type="submit">Continue</button></p>
            </form>
        </div>
    @endif

    <div class="card">
        @if ($attachment->isImage())
            <p class="meta">{{ $name !== null ? 'Click the image to pin a comment to a spot (optional).' : 'Pins show where comments were made.' }}</p>
            <div class="stage" id="stage">
                <img src="{{ route('client-review.file', $token) }}" alt="{{ $attachment->original_name }}" id="review-image">
                @foreach ($threads as $t)
                    @if ($t['x'] !== null && $t['y'] !== null)
                        <span class="pin" style="left: {{ $t['x'] * 100 }}%; top: {{ $t['y'] * 100 }}%" aria-label="Comment {{ $t['number'] }}">{{ $t['number'] }}</span>
                    @endif
                @endforeach
                <span class="pin new" id="new-pin" hidden>+</span>
            </div>
        @else
            <p><a class="btn" href="{{ route('client-review.file', $token) }}" target="_blank" rel="noopener">Open {{ $attachment->original_name }}</a></p>
        @endif
    </div>

    @if ($name !== null)
        <div class="card">
            <h2>Add a comment</h2>
            <form method="post" action="{{ route('client-review.comment', $token) }}">
                @csrf
                <input type="hidden" name="x" id="pin-x">
                <input type="hidden" name="y" id="pin-y">
                <textarea name="body" rows="3" required maxlength="2000" aria-label="Your comment"></textarea>
                @error('body')<p class="err">{{ $message }}</p>@enderror
                <p class="meta" id="pin-hint">{{ $attachment->isImage() ? 'No pin placed: this comment is about the whole image.' : '' }}</p>
                <p><button class="btn" type="submit">Send comment</button> <span class="meta">Commenting as {{ $name }}</span></p>
            </form>
        </div>
    @endif

    <div class="card">
        <h2>Comments ({{ count($threads) }})</h2>
        @forelse ($threads as $t)
            <div class="thread">
                <strong>#{{ $t['number'] }}</strong> {{ $t['body'] }}
                <div class="meta">{{ $t['author'] }}{{ $t['resolved'] ? ' · resolved' : '' }}</div>
                @foreach ($t['replies'] as $r)
                    <div class="reply">{{ $r['author'] }}: {{ $r['body'] }}</div>
                @endforeach
                @if ($name !== null)
                    <form method="post" action="{{ route('client-review.comment', $token) }}" class="reply">
                        @csrf
                        <input type="hidden" name="parent_id" value="{{ $t['id'] }}">
                        <input type="text" name="body" maxlength="2000" required aria-label="Reply to comment {{ $t['number'] }}" placeholder="Reply">
                    </form>
                @endif
            </div>
        @empty
            <p class="meta">No comments yet.</p>
        @endforelse
    </div>

    @if ($name !== null && $attachment->is_latest)
        <div class="card">
            <h2>Your decision</h2>
            <form method="post" action="{{ route('client-review.decide', $token) }}">
                @csrf
                <textarea name="note" rows="2" maxlength="2000" aria-label="What should change (needed for Request changes)" placeholder="What should change? (needed for Request changes)"></textarea>
                @error('note')<p class="err">{{ $message }}</p>@enderror
                @error('decision')<p class="err">{{ $message }}</p>@enderror
                <p class="row" style="justify-content: flex-start">
                    <button class="btn" type="submit" name="decision" value="approve">Approve</button>
                    <button class="btn alt" type="submit" name="decision" value="changes">Request changes</button>
                </p>
            </form>
        </div>
    @endif

    @if ($name !== null && $attachment->isImage())
        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            (function () {
                var img = document.getElementById('review-image');
                var pin = document.getElementById('new-pin');
                if (!img || !pin) return;
                img.addEventListener('click', function (e) {
                    var r = img.getBoundingClientRect();
                    var x = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
                    var y = Math.min(1, Math.max(0, (e.clientY - r.top) / r.height));
                    document.getElementById('pin-x').value = x.toFixed(5);
                    document.getElementById('pin-y').value = y.toFixed(5);
                    pin.style.left = (x * 100) + '%';
                    pin.style.top = (y * 100) + '%';
                    pin.hidden = false;
                    document.getElementById('pin-hint').textContent = 'Pin placed. Write your comment below and send it.';
                });
            })();
        </script>
    @endif
@endsection
