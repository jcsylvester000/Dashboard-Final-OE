@extends('client.layout')

@section('title', 'Thank you')

@section('content')
    <div class="card">
        <div class="bar"></div>
        <p class="brand">{{ $agency }}</p>
        <h1>Thank you, we've got it.</h1>
        @if ($reference)
            <p>Your reference number is <strong class="big">{{ $reference }}</strong>.</p>
            <p class="meta">Keep it handy if you contact us about this request.</p>
        @else
            <p class="meta">Your request was received.</p>
        @endif
        <p><a class="btn" href="{{ route('intake.show', $token) }}">Send another request</a></p>
    </div>
@endsection
