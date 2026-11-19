@extends('layouts.app', ['title' => 'Settings · Wanaan Office'])

@section('content')
    <form class="panel" method="POST" action="{{ route('settings.update') }}" style="max-width:560px">
        @csrf @method('PUT')
        <b>AI key</b>
        @if ($connected)
            <p class="muted" style="margin:0">Connected{{ $keyEnding ? ' with a key ending in …'.$keyEnding : ' (from the server settings)' }}. Paste a new key to replace it.</p>
        @else
            <p class="muted" style="margin:0">Not connected: the agents can't think yet. Create a key at console.anthropic.com → API keys, and paste it here. It is stored encrypted and never shown again.</p>
        @endif
        <input class="field" type="password" name="anthropic_api_key" placeholder="sk-ant-…" autocomplete="off" required>
        <button class="btn" type="submit">Save key</button>
    </form>
@endsection
