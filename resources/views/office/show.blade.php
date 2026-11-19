@extends('layouts.app', ['title' => 'Wanaan Office'])

@section('content')
    @can('owner')
        @php $unnamed = collect(array_keys($team->all()))->filter(fn ($agent) => $team->nameOf($agent) === null); @endphp
        @if ($unnamed->isNotEmpty())
            <details class="panel" @if($errors->has('name')) open @endif>
                <summary><b>Name your team</b> <span class="muted">· {{ $unnamed->count() }} still unnamed. A name is set once and stays for good.</span></summary>
                @foreach ($unnamed as $unnamedAgent)
                    <form class="nrow name-form" method="POST" action="{{ route('names.store') }}">
                        @csrf
                        <input type="hidden" name="agent" value="{{ $unnamedAgent }}">
                        <label class="nrole" for="name-{{ $unnamedAgent }}">{{ $team->title($unnamedAgent) }}</label>
                        <input class="field" id="name-{{ $unnamedAgent }}" name="name" maxlength="20" placeholder="e.g. Noura" autocomplete="off" required>
                        <button class="btn ghost" type="submit">Lock name</button>
                    </form>
                @endforeach
            </details>
        @endif
    @endcan

    @php
        $month = $spending->thisMonth();
        $budget = $spending->budget();
        $used = $spending->used();
        $level = $used === null ? 'none' : ($used >= 1 ? 'over' : ($used >= config('office.budget_warning') ? 'near' : 'ok'));
    @endphp
    <div class="spend spend-{{ $level }}" role="status">
        <span class="spend-l">AI spending</span>
        <span>Today <b>${{ number_format($spending->today(), 2) }}</b></span>
        <span>This month <b>${{ number_format($month, 2) }}</b>@if($budget) of ${{ number_format($budget, 2) }}@endif</span>
        @if ($budget)
            <span class="spend-bar" aria-hidden="true"><span style="width:{{ min(100, round(($used ?? 0) * 100)) }}%"></span></span>
            <span class="spend-msg">
                @if ($level === 'over' && $used >= 1 + config('office.manager_reserve')) Budget and managers' reserve used up: every agent has stopped until you raise it.
                @elseif ($level === 'over') Budget used up: employees have stopped; managers may use the {{ round(config('office.manager_reserve') * 100) }}% reserve only for what can't wait.
                @elseif ($level === 'near') Nearly used ({{ round($used * 100) }}%): agents are keeping their answers short.
                @else ${{ number_format($budget - $month, 2) }} left this month.
                @endif
            </span>
        @else
            <span class="spend-msg">No monthly budget set: nothing stops the spending.</span>
        @endif
        @can('owner')<a class="btn ghost sm" href="{{ route('settings') }}#budget">{{ $budget ? 'Change budget' : 'Set a budget' }}</a>@endcan
    </div>

    <div class="now" id="now" aria-live="polite"><span class="now-l">Right now</span><span>Nobody is working.</span></div>

    @php $deskSpend = $spending->byAgentThisMonth(); @endphp
    @foreach ($team->byTeam() as $teamKey => $agents)
        <section class="team">
            <h2>{{ config('office.teams.'.$teamKey) }}</h2>
            <div class="desks">
                @foreach ($agents as $deskAgent)
                    @php $profile = $team->get($deskAgent); @endphp
                    <a class="desk" data-desk="{{ $deskAgent }}" href="{{ route('office', ['with' => $deskAgent]) }}#chats" title="Talk to {{ $team->displayName($deskAgent) }} alone">
                        @if (($openQuestions[$deskAgent] ?? 0) > 0)
                            <span class="badge" title="Open questions">{{ $openQuestions[$deskAgent] }} ?</span>
                        @endif
                        <span class="name">{{ $team->displayName($deskAgent) }}</span>
                        <span class="role">@if($team->nameOf($deskAgent))<b>{{ $profile['title'] }}</b> · @endif{{ $profile['role'] }}</span>
                        <span class="tags">
                            @if ($team->hasData($deskAgent))
                                <span class="tag data">Has data</span>
                            @else
                                <span class="tag">Talk only</span>
                            @endif
                            <span class="tag">Phase {{ $profile['phase'] }}</span>
                            <span class="tag" title="AI spending this month">${{ number_format($deskSpend[$deskAgent] ?? 0, 2) }}</span>
                        </span>
                        <span class="state" data-state><span class="dot"></span><span data-step>Ready</span></span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="chats" id="chats">
        <section class="chat" data-channel="everyone">
            <div class="chat-h">
                <b>Everyone</b>
                <span class="tag">Team meeting · everyone reads, answers or argues · @Name asks one</span>
                @php $meeting = $spending->averageMeeting(); @endphp
                <span class="tag" title="Every agent takes a turn, so a meeting message costs about 12 replies">{{ $meeting !== null ? 'A meeting costs about $'.number_format($meeting, 2) : 'A meeting is about 12 replies' }}</span>
                <span class="tools"><span class="count" data-count></span><button class="btn ghost sm" type="button" data-big>Bigger</button></span>
            </div>
            <div class="msgs" data-msgs aria-live="polite"></div>
            <form class="compose" data-compose>
                <textarea class="field" rows="2" maxlength="8000" required placeholder="Give the team an instruction… everyone reads it (@Name to ask just one)"></textarea>
                <button class="btn" type="submit">Send</button>
            </form>
        </section>

        <section class="chat" data-channel="{{ $privateChannel }}" data-private-chat>
            <div class="chat-h">
                <b>Private chat with <span data-private-name>{{ $team->label($agent) }}</span></b>
                <span class="tag">Only you · kept forever</span>
                <span class="tools"><span class="count" data-count></span><button class="btn ghost sm" type="button" data-big>Bigger</button></span>
            </div>
            <div class="picker" role="radiogroup" aria-label="Who to talk to alone">
                @foreach (array_keys($team->all()) as $key)
                    <button type="button" role="radio" class="pick" data-pick="{{ $key }}"
                            data-channel-for="{{ $key === 'sec' ? 'secretary' : 'direct:'.$key }}"
                            data-label="{{ $team->label($key) }}" data-name="{{ $team->displayName($key) }}"
                            aria-checked="{{ $key === $agent ? 'true' : 'false' }}">
                        {{ $team->displayName($key) }}@if($pickerCounts[$key] > 0)<span class="pick-n">{{ $pickerCounts[$key] }}</span>@endif
                    </button>
                @endforeach
            </div>
            <div class="msgs" data-msgs aria-live="polite"></div>
            <form class="compose" data-compose>
                <textarea class="field" rows="2" maxlength="8000" required placeholder="Write to {{ $team->displayName($agent) }} alone… (Enter sends, Shift+Enter new line)"></textarea>
                <button class="btn" type="submit">Send</button>
            </form>
        </section>
    </div>

    @php
        $officeData = ['history' => $history, 'reads' => $reads, 'historyUrl' => route('office.history'), 'stateUrl' => route('office.state'), 'chatUrl' => route('office.chat')];
    @endphp
    <script type="application/json" id="office-data">{!! json_encode($officeData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
