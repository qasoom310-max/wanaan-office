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

    <div class="panel" style="max-width:560px">
        <b>Company data (ERP)</b>
        @if ($erpConnected)
            <p class="muted" style="margin:0">Connected to {{ $erpUrl }}, companies {{ $erpWorkspaces }}. Read-only: the ERP itself refuses anything but reading.</p>
        @else
            <p class="muted" style="margin:0">Not connected. Agents that need company data stay "Talk only" until it is.</p>
        @endif
        <p class="muted" style="margin:0">The connection is set from the server's command line (php artisan office:connect-erp), so the ERP key never passes through a web page.</p>
    </div>
@endsection
