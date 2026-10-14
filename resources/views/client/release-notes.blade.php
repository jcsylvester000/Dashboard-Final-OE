@extends('client.layout')

@section('title', $sprint->name.' release notes')

@section('content')
    <div class="card">
        <div class="bar"></div>
        <div class="row">
            <p class="brand">{{ $agency }} · release notes</p>
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 40px">@endif
        </div>
        <div class="notes">{!! $html !!}</div>
        <p class="meta">{{ $sprint->closed_at ? 'Released '.$sprint->closed_at->format('M j, Y') : '' }}</p>
    </div>
@endsection
