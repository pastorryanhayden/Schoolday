@extends('layout')
@section('title', 'Games')
@section('content')
<div class="page-heading"><div><h1>Games</h1><p class="muted">{{ $parent ? 'Set up practice for this week’s lessons. These games are optional; schedule a required practice assignment separately when needed.' : 'Extra practice. Play as often as you like.' }}</p></div></div>
<div class="game-library-layout {{ $parent ? '' : 'student-library' }}">
<div class="stack">
@forelse($games as $game)
<section class="library-game">
 <span class="library-game-kind">{{ $game->configuration['activity_type']==='spelling' ? 'Aa · Spelling' : '× · Times tables' }}</span><h2>{{ $game->title }}</h2>
 <p class="muted">{{ $game->configuration['activity_type']==='spelling' ? count($game->configuration['spelling_words']).' '.Str::plural('word', count($game->configuration['spelling_words'])).' · look, listen, choose' : ($game->configuration['fact_min'] ?? 1).'–'.($game->configuration['fact_max'] ?? 12).' times tables' }} · {{ ($game->configuration['question_target'] ?? null) ? $game->configuration['question_target'].' questions' : $game->configuration['minutes'].' minutes' }} per round</p>
 <p class="small muted">{{ $game->rounds_count }} {{ Str::plural('round',$game->rounds_count) }}@if(!$game->active) · Archived @endif</p>
 @if($parent)<details class="mt-4"><summary>Edit game</summary><form action="{{ route('games.update',$game) }}" method="post" class="stack mt-4">@csrf<x-game-editor :game="$game"/><button class="btn primary">Save game</button></form></details>
 @elseif($routineState['day'])<form action="{{ route('games.play',$game) }}" method="post" class="mt-4">@csrf<input type="hidden" name="version" value="{{ $routineState['version'] }}" :value="routineVersion"><button class="btn primary" :disabled="routineStale">{{ $game->rounds_count ? 'Play again' : 'Play' }} →</button></form>
 @else<p class="small">Start your schoolday using the bottom bar, then choose a game.</p>@endif
 @if($game->rounds->first())<a class="text-btn mt-3" href="{{ route('practice.show',$game->rounds->first()) }}">Latest round’s results →</a>@endif
</section>
@empty<p class="muted">{{ $parent ? 'Add the first game below.' : 'Dad hasn’t added any games yet.' }}</p>@endforelse
</div>
@if($parent)<section class="panel self-start"><h2>Add a game</h2><p class="muted small">Name weekly lists so Noah can tell them apart. Archive old games to hide them without losing results.</p><form action="{{ route('games.store') }}" method="post" class="stack mt-5">@csrf<x-game-editor/><button class="btn primary">Add game</button></form></section>@endif
</div>
@endsection
