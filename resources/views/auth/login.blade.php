@extends('layouts.app', ['title' => 'Sign in · Wanaan Office'])

@section('content')
    <form method="POST" action="{{ route('login') }}" class="panel" style="max-width:380px;margin:12vh auto 0">
        @csrf
        <div class="brand">Wanaan Office</div>
        <label>Email<br><input class="field" style="width:100%" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></label>
        <label>Password<br><input class="field" style="width:100%" type="password" name="password" required autocomplete="current-password"></label>
        <label class="muted"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="btn" type="submit">Sign in</button>
    </form>
@endsection
