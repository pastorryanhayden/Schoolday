<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#244c40"><meta name="description" content="Noah’s schoolwork, daily plan, and family messages."><link rel="manifest" href="/manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="/icon.svg"><title>@yield('title','Schoolday') · Noah’s Schoolday</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="{{ auth()->check()&&auth()->user()->role==='student'?'student-account':'' }} @yield('body-class')" x-data="shell({{ auth()->check()?'true':'false' }}, {{ auth()->check()?\App\Models\Activity::max('id')??0:0 }}, @js($routineState))">
@auth
@if(auth()->user()->role==='student' && request()->routeIs('practice.show'))
{{-- Student games provide their own immersive controls. --}}
@else
<header class="app-header" x-data="{view:location.hash.slice(1)||'overview'}" @hashchange.window="view=location.hash.slice(1)||'overview'">
 <a href="{{ route('home') }}" class="brand">Schoolday</a>
 <nav aria-label="Main navigation">
  <a href="/" class="nav-link" :aria-current="view==='overview' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null"><x-icon name="home"/>Today</a>
  <a href="/#messages" class="nav-link" :aria-current="view==='messages' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null"><x-icon name="message"/>Messages <span class="nav-count" :aria-label="unread+' unread messages'" aria-live="polite" x-show="unread>0" x-text="unread" x-cloak></span></a>
  @if(auth()->user()->role==='student')<button type="button" class="nav-link nav-game-button" @click="openGames($el)" aria-haspopup="dialog"><x-icon name="play"/>Games</button>@endif
  @if(auth()->user()->role==='parent')
   <a href="{{ route('games') }}" class="nav-link" @if(request()->routeIs('games')) aria-current="page" @endif><x-icon name="play"/>Games</a>
   <a href="/#planner" class="nav-link" :aria-current="view==='planner' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null"><x-icon name="calendar"/>Planner</a>
   <a href="/#activity" class="nav-link" :aria-current="view==='activity' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null"><x-icon name="activity"/>Activity</a>
   <a href="{{ route('settings') }}" class="nav-link" @if(request()->routeIs('settings')) aria-current="page" @endif><x-icon name="book"/>Books</a>
  @endif
 </nav>
 <div class="profile"><span>{{ auth()->user()->name }}{{ auth()->user()->role==='parent' ? ' · Parent' : '' }}</span><form action="/logout" method="post">@csrf<button class="text-btn" aria-label="Sign out">Sign out</button></form></div>
</header>
@endif
<div class="app-main">
@unless(auth()->user()->role==='student' && request()->routeIs('practice.show'))
@if($routineState['day'] && substr($routineState['day']['date'], 0, 10) !== today()->toDateString())
<div class="open-day-alert" role="alert"><strong>This day was left open</strong> from {{ \Carbon\Carbon::parse($routineState['day']['started_at'])->timezone(config('app.timezone'))->format('M j, g:i a') }}. {{ auth()->user()->role==='parent' ? 'Check the activity timeline and correct the stop time if needed.' : 'Finish it, or ask Dad to correct the stop time.' }} @if(auth()->user()->role==='student')<a href="{{ route('routine.finish') }}">Review and finish the day →</a>@else<a href="/#activity">View timeline →</a>@endif</div>
@endif
<div class="live-alert" x-show="changed" x-cloak role="status">New activity is available. <a href="" @click.prevent="location.reload()">Refresh to see updates →</a></div>
<div class="live-alert" x-show="routineStale" x-cloak role="status"><strong x-text="routineLabel"></strong> · Activity changed on another page or device. Your drafts are safe. <a href="" @click.prevent="location.reload()">Refresh before using activity controls →</a></div>
<div class="network-alert" x-show="!online" x-cloak role="status">Connection interrupted. Keep this page open; drafts stay on this device. Retry when connected.</div>
@endunless
@else<div class="login-shell">@endauth
<main class="page">
@if(session('success'))<div class="flash success" role="status"><x-icon name="check"/>{{ session('success') }}</div>@endif
@if($errors->any())<div class="flash error" role="alert"><strong>Please check:</strong> {{ $errors->first() }}</div>@endif
@yield('content')
</main></div>
@auth @if(auth()->user()->role==='student' && !request()->routeIs('practice.show'))
<nav class="day-controls" aria-label="Schoolday controls">
 <div class="day-controls-inner">
  <p class="day-controls-status"><span class="small muted">Current activity</span><strong x-text="routineLabel">{{ $routineState['label'] }}</strong></p>
  <div class="day-controls-actions">
   <div x-show="!dayRunning"><x-routine-action action="day-start" :version="$routineState['version']" label="Start my day" primary/></div>
   <div x-show="dayRunning && routineKind==='break'" x-cloak><x-routine-action action="resume" :version="$routineState['version']" label="Resume work" primary/></div>
   <div x-show="dayRunning && routineKind!=='break'"><x-routine-action action="break" :version="$routineState['version']" label="Take a break"/></div>
   <div x-show="dayRunning && routineKind==='work'" x-cloak><x-routine-action action="activity-stop" :version="$routineState['version']" label="Stop this activity"/></div>
   @unless(request()->routeIs('routine.finish'))<a x-show="dayRunning" class="btn primary" href="{{ route('routine.finish') }}">Finish my day →</a>@endunless
  </div>
 </div>
</nav>
@endif @endauth
@auth @if(auth()->user()->role==='student')
<div class="games-overlay" x-show="gamesOpen" x-cloak @keydown.escape.window="if(gamesOpen) closeGames()" @keydown.tab="trapGames($event)">
 <section class="games-drawer" role="dialog" aria-modal="true" aria-labelledby="games-drawer-title" x-ref="gamesPanel" @click.outside="if(gamesOpen) closeGames()">
  <div class="section-heading"><h2 id="games-drawer-title">Pick a game</h2><button type="button" class="btn secondary" x-ref="gamesClose" @click="closeGames()" aria-label="Close games">Close ×</button></div>
  <p class="muted">Extra practice. Play as often as you like.</p>
  <div class="stack mt-5">
  @forelse($libraryGames as $game)<article class="library-game"><span class="library-game-kind">{{ $game->configuration['activity_type']==='spelling' ? 'Aa · Spelling' : '× · Times tables' }}</span><h3>{{ $game->title }}</h3><p class="small muted">{{ $game->configuration['activity_type']==='spelling' ? count($game->configuration['spelling_words']).' '.Str::plural('word',count($game->configuration['spelling_words'])).' · all three modes' : ($game->configuration['fact_min'] ?? 1).'–'.($game->configuration['fact_max'] ?? 12).' times tables' }} · {{ ($game->configuration['question_target'] ?? null) ? $game->configuration['question_target'].' questions' : $game->configuration['minutes'].' minutes' }}</p>
  <form action="{{ route('games.play',$game) }}" method="post">@csrf<input type="hidden" name="version" value="{{ $routineState['version'] }}" :value="routineVersion"><button class="btn primary full" :disabled="routineStale || !dayRunning || gameBusy">Play a new round →</button></form></article>@empty<p class="muted">Dad hasn’t added games yet.</p>@endforelse
  </div><p class="small muted mt-5" x-show="!dayRunning">Start your schoolday before playing.</p><a class="text-btn mt-4" href="{{ route('games') }}">All games & latest rounds →</a>
 </section>
</div>
@endif @endauth
</body></html>
