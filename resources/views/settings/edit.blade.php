@extends('layouts.app', ['title' => 'Settings · Wanaan Office'])

@section('content')
    <form class="panel" id="budget" method="POST" action="{{ route('settings.budget') }}" style="max-width:560px">
        @csrf @method('PUT')
        <b>Monthly AI budget</b>
        <p class="muted" style="margin:0">From 80% every agent keeps its answers short. At 100% employees stop; managers may go up to {{ round(config('office.manager_reserve') * 100) }}% over, only for what can't wait. At {{ 100 + round(config('office.manager_reserve') * 100) }}% everyone stops until you raise it. Messages are always saved. Set your Anthropic console limit at least this high: ${{ $spending->ceiling() !== null ? number_format($spending->ceiling(), 2) : '…' }}. These figures are estimates at list prices; the exact bill is in the Anthropic console.</p>
        <p style="margin:0">This month so far: <b>${{ number_format($spending->thisMonth(), 2) }}</b>@if($spending->budget()) of ${{ number_format($spending->budget(), 2) }}@endif · today ${{ number_format($spending->today(), 2) }}</p>
        <div class="inline-form">
            <input class="field" type="number" name="budget" min="1" max="100000" step="1" value="{{ $spending->budget() }}" placeholder="e.g. 50" aria-label="Monthly budget in US dollars" required>
            <button class="btn" type="submit">Save budget</button>
        </div>
        @php $byAgent = $spending->byAgentThisMonth(); @endphp
        @if ($byAgent !== [])
            <div class="list" style="margin-top:4px">
                @foreach ($byAgent as $agent => $cost)
                    <div class="meta" style="justify-content:space-between"><span>{{ $team->exists($agent) ? $team->label($agent) : $agent }}</span><b>${{ number_format($cost, 2) }}</b></div>
                @endforeach
            </div>
        @endif
    </form>

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

    <form class="panel" id="erp" method="POST" action="{{ route('settings.erp') }}" style="max-width:560px">
        @csrf @method('PUT')
        <b>Company data (ERP)</b>
        @if ($erpConnected)
            <p class="muted" style="margin:0">Connected to <b>{{ $erpUrl }}</b>, companies {{ $erpWorkspaces }}. Read-only: the ERP itself refuses anything but reading. Connect again below to switch to another ERP or key.</p>
        @else
            <p class="muted" style="margin:0">Not connected. Agents that need company data stay "Talk only" until it is.</p>
        @endif
        <p class="muted" style="margin:0">In the ERP, sign in as the owner, open <b>Settings → AI office</b>, press <b>Create key</b> and copy it. Paste it here.</p>
        <input class="field" type="url" name="erp_url" value="{{ old('erp_url', $erpUrl ?: 'https://erp.wanaan-bh.com') }}" placeholder="https://erp.wanaan-bh.com" aria-label="ERP address" required>
        <input class="field" type="password" name="erp_key" placeholder="office_…" autocomplete="off" aria-label="ERP office key" required>
        <button class="btn" type="submit">Connect the ERP</button>
    </form>

    <div class="panel" style="max-width:560px">
        <b>SEO system</b>
        @if ($seoConnected)
            <p class="muted" style="margin:0">Connected to {{ $seoUrl }}. Read-only. The marketing team follows its keywords and writing calendar.</p>
        @else
            <p class="muted" style="margin:0">Not connected. The marketing team stays "Talk only" until it is (php artisan office:connect-seo).</p>
        @endif
    </div>
@endsection
