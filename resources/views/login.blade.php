@extends('layout')
@section('title','Welcome')
@section('content')
<div class="login-card"><a href="/" class="brand"><span class="brand-mark"><x-icon name="book" size="26"/></span><span>schoolday<span class="brand-dot">.</span></span></a><div class="eyebrow">NOAH’S LEARNING SPACE</div><h1>A good day<br>starts here.</h1><p class="muted">A little structure. Room to grow.<br>Sign in to your private family workspace.</p><form action="/login" method="post" class="stack">@csrf<label>Email<input name="email" type="email" autocomplete="username" required value="{{ old('email') }}"></label><label>Password<input name="password" type="password" autocomplete="current-password" required></label><label class="check-label"><input type="checkbox" name="remember" value="1" checked> Keep me signed in on this device</label><button class="btn primary">Sign in <x-icon name="arrow"/></button></form><small class="muted">Family access only. No public registration.</small></div>
@endsection
