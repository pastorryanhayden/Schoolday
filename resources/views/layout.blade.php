<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="theme-color" content="#244c40"><meta name="description" content="A little structure. Room to grow. Noah’s private schoolwork companion."><link rel="manifest" href="/manifest.webmanifest"><link rel="icon" type="image/svg+xml" href="/icon.svg"><title>@yield('title','Schoolday') · Noah’s Schoolday</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body class="{{ auth()->check()&&auth()->user()->role==='student'?'student-account':'' }}" x-data="shell({{ auth()->check()?'true':'false' }}, {{ auth()->check()?\App\Models\Activity::max('id')??0:0 }})">
@auth
<aside class="sidebar">
 <a href="/" class="brand"><span class="brand-mark"><x-icon name="book" size="25"/></span><span>schoolday<span class="brand-dot">.</span><small>A LITTLE STRUCTURE. ROOM TO GROW.</small></span></a>
 <div class="workspace-label">{{ auth()->user()->role==='parent'?'PARENT WORKSPACE':'YOUR WORKSPACE' }}</div>
 <nav><a href="/" class="nav-link {{ request()->is('/')?'selected':'' }}"><x-icon name="home"/> {{ auth()->user()->role==='parent'?'Day overview':'My schoolday' }}</a><a href="/#messages" class="nav-link"><x-icon name="message"/> Messages <span class="nav-count" x-show="unread>0" x-text="unread" x-cloak></span></a>@if(auth()->user()->role==='parent')<a href="/#planner" class="nav-link"><x-icon name="calendar"/> Plan assignments</a><a href="/#activity" class="nav-link"><x-icon name="activity"/> Activity log</a><a href="{{ route('settings') }}" class="nav-link {{ request()->routeIs('settings')?'selected':'' }}"><x-icon name="book"/> Settings · Books</a>@endif</nav>
 <div class="sidebar-note"><span class="tiny-leaf">✳</span><p>One assignment.<br>One step forward.</p><small>Progress happens a little at a time.</small></div>
 <div class="profile"><span class="avatar">{{ auth()->user()->role==='parent'?'RH':'N' }}</span><div><strong>{{ auth()->user()->name }}</strong><small>{{ auth()->user()->role==='parent'?'Parent account':'Student account' }}</small></div><form action="/logout" method="post">@csrf<button class="icon-btn" aria-label="Sign out"><x-icon name="logout"/></button></form></div>
</aside>
<div class="app-main"><header class="topbar"><span class="breadcrumb">Noah’s learning space <span>/</span> @yield('title','Day overview')</span><div class="topbar-right"><span class="network-dot" :class="online?'':'offline'"></span><span x-text="online?'Connected to schoolday':'Connection interrupted'"></span><span class="today-pill">{{ today()->format('D, M j') }}</span></div></header>
<div class="live-alert" x-show="changed" x-cloak role="status">New activity is available. <a href="" @click.prevent="location.reload()">Refresh to see updates →</a></div>
<div class="network-alert" x-show="!online" x-cloak role="status">Connection interrupted. Keep this page open; drafts stay on this device. Retry when connected.</div>
@else<div class="login-shell">@endauth
<main class="page">
@if(session('success'))<div class="flash success" role="status"><x-icon name="check"/>{{ session('success') }}</div>@endif
@if($errors->any())<div class="flash error" role="alert"><strong>Please check:</strong> {{ $errors->first() }}</div>@endif
@yield('content')
</main></div>
@auth @if(auth()->user()->role==='student')
<nav class="student-actions" aria-label="Student quick actions"><a href="/?help=1#messages" class="btn primary"><x-icon name="message" size="18"/>Ask Dad</a><form action="/break" method="post">@csrf<input type="hidden" name="on_break" value="{{ auth()->user()->on_break?0:1 }}"><button class="btn secondary"><x-icon name="pause" size="16"/>{{ auth()->user()->on_break?'Resume work':'Take a break' }}</button></form></nav>
@endif @endauth
</body></html>
