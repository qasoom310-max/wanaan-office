@extends('layouts.app', ['title' => 'Wanaan Office'])

@section('content')
    @can('owner')
        @php $unnamed = collect(array_keys($team->all()))->filter(fn ($agent) => $team->nameOf($agent) === null); @endphp
        @if ($unnamed->isNotEmpty())
            <details class="panel" @if($errors->has('name')) open @endif>
                <summary><b>Name your team</b> <span class="muted">· {{ $unnamed->count() }} still unnamed. A name is set once and stays for good.</span></summary>
                @foreach ($unnamed as $agent)
                    <form class="nrow name-form" method="POST" action="{{ route('names.store') }}">
                        @csrf
                        <input type="hidden" name="agent" value="{{ $agent }}">
                        <label class="nrole" for="name-{{ $agent }}">{{ $team->title($agent) }}</label>
                        <input class="field" id="name-{{ $agent }}" name="name" maxlength="20" placeholder="e.g. Noura" autocomplete="off" required>
                        <button class="btn ghost" type="submit">Lock name</button>
                    </form>
                @endforeach
            </details>
        @endif
    @endcan

    <div class="now" id="now" aria-live="polite"><span class="now-l">Right now</span><span>Nobody is working.</span></div>

    @foreach ($team->byTeam() as $teamKey => $agents)
        <section class="team">
            <h2>{{ config('office.teams.'.$teamKey) }}</h2>
            <div class="desks">
                @foreach ($agents as $agent)
                    @php $profile = $team->get($agent); @endphp
                    <a class="desk" data-desk="{{ $agent }}" href="{{ route('office', ['with' => $agent]) }}#chats"
                       @if($direct === $agent) aria-current="true" @endif title="Talk to {{ $team->displayName($agent) }} directly">
                        @if (($openQuestions[$agent] ?? 0) > 0)
                            <span class="badge" title="Open questions">{{ $openQuestions[$agent] }} ?</span>
                        @endif
                        <span class="name">{{ $team->displayName($agent) }}</span>
                        <span class="role">@if($team->nameOf($agent))<b>{{ $profile['title'] }}</b> · @endif{{ $profile['role'] }}</span>
                        <span class="tags">
                            @if ($team->hasData($agent))
                                <span class="tag data">Has data</span>
                            @else
                                <span class="tag">Talk only</span>
                            @endif
                            <span class="tag">Phase {{ $profile['phase'] }}</span>
                        </span>
                        <span class="state" data-state><span class="dot"></span><span data-step>Ready</span></span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="chats" id="chats">
        @foreach ($chats as $channel)
            @php $private = $channel !== \App\Models\Message::EVERYONE; @endphp
            <section class="chat" data-channel="{{ $channel }}">
                <div class="chat-h">
                    <b>{{ $channels->title($channel) }}</b>
                    <span class="tag">{{ $private ? 'Private · only you' : 'Team meeting · everyone reads, answers or argues · @Name asks one' }}</span>
                    <span class="tools">
                        <span class="count" data-count></span>
                        <button class="btn ghost sm" type="button" data-big>Bigger</button>
                        @if (str_starts_with($channel, 'direct:'))
                            <a class="btn ghost sm" href="{{ route('office') }}">Close</a>
                        @endif
                    </span>
                </div>
                <div class="msgs" data-msgs aria-live="polite"></div>
                <form class="compose" data-compose>
                    <textarea class="field" rows="2" maxlength="8000" required
                              placeholder="{{ $channel === \App\Models\Message::EVERYONE ? 'Give the team an instruction… everyone reads it (@Name to ask just one)' : 'Write to '.$team->displayName($channel === 'secretary' ? 'sec' : substr($channel, 7)).'… (Enter sends, Shift+Enter new line)' }}"></textarea>
                    <button class="btn" type="submit">Send</button>
                </form>
            </section>
        @endforeach
    </div>

    @php
        $officeData = ['history' => $history, 'reads' => $reads, 'stateUrl' => route('office.state'), 'chatUrl' => route('office.chat')];
    @endphp
    <script type="application/json" id="office-data">{!! json_encode($officeData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
