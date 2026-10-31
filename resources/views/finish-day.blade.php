@extends('layout')
@section('title', 'Finish my day')
@section('content')
<a class="back-link" href="{{ route('home') }}">← Back to my schoolday</a>
<div class="page-heading"><div><div class="eyebrow">BEFORE YOU GO</div><h1>Take a look at what’s left.</h1><p class="muted">Finishing the day stops your reported time. It does not complete or approve assignments.</p></div></div>
<section class="panel stack">
    <h2>{{ $remaining->count() }} {{ Str::plural('assignment', $remaining->count()) }} still to finish</h2>
    @forelse($remaining as $assignment)<a class="mini-work" href="{{ route('assignment', $assignment) }}"><span>{{ $assignment->title }}<small>{{ $assignment->subject }} · {{ $assignment->date->format('M j') }}</small></span><x-status :status="$assignment->status"/></a>@empty<p>All assigned work through today has been submitted or approved.</p>@endforelse
    <p class="muted">{{ $pending }} {{ Str::plural('assignment', $pending) }} awaiting Dad’s review. Submitted work is separate from approved work.</p>
    @if($routine['open_day'])
        @if($remaining->count())<p class="attention">You can end your day, but this work stays on your checklist. Talk to Dad about anything you couldn’t finish.</p>@endif
        <x-routine-action action="day-finish" :version="$routine['version']" label="Finish my day" primary/>
    @else<p>Your schoolday is already stopped.</p>@endif
</section>
@endsection
