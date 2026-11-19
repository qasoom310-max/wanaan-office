@extends('layouts.app', ['title' => 'Password · Wanaan Office'])

@section('content')
    <form method="POST" action="{{ route('password.update') }}" class="panel" style="max-width:420px">
        @csrf @method('PUT')
        <b>Choose your own password</b>
        <p class="muted" style="margin:0">At least 12 characters.</p>
        <label>Current (one-time) password<br><input class="field" style="width:100%" type="password" name="current_password" required autocomplete="current-password"></label>
        <label>New password<br><input class="field" style="width:100%" type="password" name="password" required autocomplete="new-password"></label>
        <label>New password again<br><input class="field" style="width:100%" type="password" name="password_confirmation" required autocomplete="new-password"></label>
        <button class="btn" type="submit">Save password</button>
    </form>
@endsection
