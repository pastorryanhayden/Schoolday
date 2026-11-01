<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#244c40"><meta name="description" content="Noah’s schoolwork, daily plan, and family messages."><link rel="manifest" href="/manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="/icon.svg"><title>@yield('title','Schoolday') · Noah’s Schoolday</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="{{ auth()->check()&&auth()->user()->role==='student'?'student-account':'' }}" x-data="shell({{ auth()->check()?'true':'false' }}, {{ auth()->check()?\App\Models\Activity::max('id')??0:0 }}, @js($routineState))">
@auth
<header class="app-header" x-data="{view:location.hash.slice(1)||'overview'}" @hashchange.window="view=location.hash.slice(1)||'overview'">
 <a href="{{ route('home') }}" class="brand">Schoolday</a>
 <nav aria-label="Main navigation">
  <a href="/" class="nav-link" :aria-current="view==='overview' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null">Today</a>
  <a href="/#messages" class="nav-link" :aria-current="view==='messages' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null">Messages <span class="nav-count" x-show="unread>0" x-text="unread" x-cloak></span></a>
  @if(auth()->user()->role==='parent')
   <a href="/#planner" class="nav-link" :aria-current="view==='planner' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null">Planner</a>
   <a href="/#activity" class="nav-link" :aria-current="view==='activity' &amp;&amp; @js(request()->routeIs('home')) ? 'page' : null">Activity</a>
   <a href="{{ route('settings') }}" class="nav-link" @if(request()->routeIs('settings')) aria-current="page" @endif>Books</a>
  @endif
 </nav>
 <div class="profile"><span>{{ auth()->user()->name }}{{ auth()->user()->role==='parent' ? ' · Parent' : '' }}</span><form action="/logout" method="post">@csrf<button class="text-btn" aria-label="Sign out">Sign out</button></form></div>
</header>
<div class="app-main">
<div class="live-alert" x-show="changed" x-cloak role="status">New activity is available. <a href="" @click.prevent="location.reload()">Refresh to see updates →</a></div>
<div class="live-alert" x-show="routineStale" x-cloak role="status"><strong x-text="routineLabel"></strong> · Activity changed on another page or device. Your drafts are safe. <a href="" @click.prevent="location.reload()">Refresh before using activity controls →</a></div>
<div class="network-alert" x-show="!online" x-cloak role="status">Connection interrupted. Keep this page open; drafts stay on this device. Retry when connected.</div>
@else<div class="login-shell">@endauth
<main class="page">
@if(session('success'))<div class="flash success" role="status"><x-icon name="check"/>{{ session('success') }}</div>@endif
@if($errors->any())<div class="flash error" role="alert"><strong>Please check:</strong> {{ $errors->first() }}</div>@endif
@yield('content')
</main></div>
@auth @if(auth()->user()->role==='student')
<nav class="student-actions" aria-label="Student quick actions"><a href="/?help=1#messages" class="btn primary"><x-icon name="message" size="18"/>Ask Dad</a>
@if($routineState['day'])<x-routine-action :action="($routineState['current']['kind'] ?? '') === 'break' ? 'resume' : 'break'" :version="$routineState['version']" :label="($routineState['current']['kind'] ?? '') === 'break' ? 'Resume work' : 'Take a break'"/>@else<a href="/" class="btn secondary">My schoolday</a>@endif</nav>
@endif @endauth
</body></html>
