@extends('layout')
@section('title', 'Check the correction')
@section('content')
<section class="panel stack"><div class="eyebrow">THIS ACTION WAS NOT SAVED</div><h1>Check those details.</h1><p>{{ $exception->getMessage() ?: 'The values could not be saved. Please check and try again.' }}</p><p class="muted">Original timeline records are unchanged.</p><a href="{{ route('home') }}#activity" class="btn primary">Return to the timeline →</a></section>
@endsection
