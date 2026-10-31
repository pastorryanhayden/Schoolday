@extends('layout')
@section('title', 'Refresh before continuing')
@section('content')
<section class="panel stack"><div class="eyebrow">THIS ACTION WAS NOT SAVED</div><h1>Take a fresh look.</h1><p>{{ $exception->getMessage() ?: 'The work or activity changed. Refresh before trying again.' }}</p><p class="muted">Your saved notes and photos are safe. Go back to the schoolday to see the current activity.</p><a href="{{ route('home') }}" class="btn primary">Open current schoolday →</a></section>
@endsection
