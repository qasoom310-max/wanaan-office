@extends('layouts.app', ['title' => 'Facts · Wanaan Office'])

@section('content')
    <p class="muted" style="margin:0;max-width:70ch">Facts are things the owner has confirmed. Every agent reads them before it answers, so a fact is never asked again. Answering a question adds a fact.</p>

    @can('owner')
        <form class="inline-form" method="POST" action="{{ route('facts.store') }}">
            @csrf
            <input class="field" name="text" maxlength="2000" placeholder="Add a fact every agent should know, e.g. Deposits are refunded within 7 days" required>
            <button class="btn" type="submit">Save fact</button>
        </form>
    @endcan

    <div class="list">
        @forelse ($facts as $fact)
            <div class="row">
                <div>{{ $fact->text }}</div>
                <div class="meta">
                    <span>Confirmed by {{ $fact->confirmer?->name }}</span>
                    <span>{{ $fact->created_at->format('j M Y') }}</span>
                    @foreach ($fact->agents ?? [] as $agent)
                        <span class="chip">{{ $team->exists($agent) ? $team->displayName($agent) : $agent }}</span>
                    @endforeach
                    @if (empty($fact->agents))<span class="chip">Everyone</span>@endif
                </div>
            </div>
        @empty
            <div class="empty">No facts yet.</div>
        @endforelse
    </div>
@endsection
