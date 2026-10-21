@extends('client.layout')

@section('title', $form->name)

@section('content')
    {{-- E-42: public intake form. Conditional sections/fields toggle with the small script below;
         the server applies the same rules (App\Domain\Forms\FormSchema). --}}
    <div class="card">
        <div class="bar"></div>
        @if ($logo)<img src="{{ $logo }}" alt="{{ $agency }}" style="max-height: 36px">@endif
        <p class="brand">{{ $agency }}</p>
        <h1>{{ $form->name }}</h1>
        @if ($form->description)<p class="meta pre">{{ $form->description }}</p>@endif
    </div>

    @if ($errors->any())
        <div class="card" role="alert" style="border-color: var(--late)">
            <h2 class="late">Please check these answers</h2>
            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="post" action="{{ route('intake.submit', $token) }}" enctype="multipart/form-data" class="card" id="intake" novalidate>
        @csrf
        <input type="hidden" name="stamp" value="{{ $stamp }}">
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
        <div style="position:absolute; left:-9999px" aria-hidden="true">
            <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <p style="margin-top:0"><label><strong>Your name</strong> <span class="late">*</span><br>
            <input type="text" name="submitter_name" value="{{ old('submitter_name') }}" required maxlength="120" style="width:100%; padding:8px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit"></label></p>

        @foreach ($schema['sections'] as $section)
            <fieldset data-section data-show='@json($section['show_if'])' style="border:0; padding:0; margin:16px 0 0">
                @if ($section['title'])<legend style="font-weight:600; font-size:16px; margin-bottom:8px">{{ $section['title'] }}</legend>@endif
                @foreach ($section['fields'] as $f)
                    @php($name = 'answers['.$f['key'].']')
                    @php($old = old('answers.'.$f['key']))
                    <div data-field="{{ $f['key'] }}" data-type="{{ $f['type'] }}" data-show='@json($f['show_if'])' style="margin-bottom:14px">
                        <label for="f-{{ $f['key'] }}"><strong>{{ $f['label'] }}</strong>@if ($f['required']) <span class="late">*</span>@endif</label>
                        @if ($f['help'])<div class="meta">{{ $f['help'] }}</div>@endif
                        @php($style = 'width:100%; padding:8px; border:1px solid var(--line); border-radius:8px; background:transparent; color:inherit; margin-top:4px')
                        @switch($f['type'])
                            @case('textarea')
                                <textarea id="f-{{ $f['key'] }}" name="{{ $name }}" rows="4" maxlength="5000" style="{{ $style }}">{{ $old }}</textarea>
                                @break
                            @case('number')
                                <input id="f-{{ $f['key'] }}" type="number" step="any" name="{{ $name }}" value="{{ $old }}" style="{{ $style }}">
                                @break
                            @case('date')
                                <input id="f-{{ $f['key'] }}" type="date" name="{{ $name }}" value="{{ $old }}" style="{{ $style }}">
                                @break
                            @case('url')
                                <input id="f-{{ $f['key'] }}" type="url" name="{{ $name }}" value="{{ $old }}" placeholder="https://" style="{{ $style }}">
                                @break
                            @case('select')
                                <select id="f-{{ $f['key'] }}" name="{{ $name }}" style="{{ $style }}">
                                    <option value="">Choose…</option>
                                    @foreach ($f['options'] as $o)<option value="{{ $o }}" @selected($old === $o)>{{ $o }}</option>@endforeach
                                </select>
                                @break
                            @case('multiselect')
                                <div id="f-{{ $f['key'] }}">
                                    @foreach ($f['options'] as $o)
                                        <label style="display:block"><input type="checkbox" name="{{ $name }}[]" value="{{ $o }}" @checked(in_array($o, (array) $old, true))> {{ $o }}</label>
                                    @endforeach
                                </div>
                                @break
                            @case('yesno')
                                <div id="f-{{ $f['key'] }}">
                                    <label><input type="radio" name="{{ $name }}" value="yes" @checked($old === 'yes')> Yes</label>
                                    <label style="margin-left:12px"><input type="radio" name="{{ $name }}" value="no" @checked($old === 'no')> No</label>
                                </div>
                                @break
                            @case('rating')
                                <div id="f-{{ $f['key'] }}">
                                    @for ($i = 1; $i <= 5; $i++)<label style="margin-right:10px"><input type="radio" name="{{ $name }}" value="{{ $i }}" @checked((string) $old === (string) $i)> {{ $i }}</label>@endfor
                                </div>
                                @break
                            @case('file')
                                <input id="f-{{ $f['key'] }}" type="file" name="files[{{ $f['key'] }}][]" @if (($f['max_files'] ?? 1) > 1) multiple @endif style="margin-top:4px">
                                <div class="meta">Up to {{ $f['max_files'] ?? 1 }} file(s).</div>
                                @break
                            @default
                                <input id="f-{{ $f['key'] }}" type="text" name="{{ $name }}" value="{{ $old }}" maxlength="500" style="{{ $style }}">
                        @endswitch
                    </div>
                @endforeach
            </fieldset>
        @endforeach

        <p class="meta">Your answers go to {{ $agency }} to handle this request.</p>
        <button type="submit" class="btn" style="border:0; cursor:pointer">Send</button>
    </form>

    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            const form = document.getElementById('intake');
            const value = (key) => {
                const wrap = form.querySelector('[data-field="' + key + '"]');
                if (!wrap || wrap.hidden) return null;
                const type = wrap.dataset.type;
                if (type === 'multiselect') return [...wrap.querySelectorAll('input:checked')].map((i) => i.value);
                if (type === 'yesno' || type === 'rating') return wrap.querySelector('input:checked')?.value ?? null;
                const el = wrap.querySelector('input, select, textarea');
                return el && el.value !== '' ? el.value : null;
            };
            const passes = (cond) => {
                if (!cond || !cond.field) return true;
                const v = value(cond.field);
                const filled = v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
                const t = String(cond.value ?? '');
                switch (cond.op) {
                    case 'filled': return filled;
                    case 'empty': return !filled;
                    case 'neq': return !filled || (Array.isArray(v) ? !v.includes(t) : String(v) !== t);
                    case 'includes': return Array.isArray(v) && v.includes(t);
                    default: return filled && (Array.isArray(v) ? v.includes(t) : String(v) === t);
                }
            };
            const update = () => {
                form.querySelectorAll('[data-section]').forEach((section) => {
                    section.hidden = !passes(JSON.parse(section.dataset.show || 'null'));
                    section.querySelectorAll('[data-field]').forEach((f) => {
                        f.hidden = section.hidden || !passes(JSON.parse(f.dataset.show || 'null'));
                    });
                });
            };
            form.addEventListener('input', update);
            form.addEventListener('change', update);
            update();
        })();
    </script>
@endsection
