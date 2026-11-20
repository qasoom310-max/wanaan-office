@extends('layouts.app', ['title' => 'IT board · Wanaan Office'])

@section('content')
    <p class="muted" style="margin:0;max-width:75ch">Improvements the team asks IT for. Nothing is built until you approve it. Once you approve, the IT Manager writes the requirement; then copy it into Claude Code to build it.</p>

    @foreach ($sections as $status => $section)
        @if ($section['ideas']->isNotEmpty())
            <h2 class="board-h">{{ $section['label'] }} <span class="muted">· {{ $section['ideas']->count() }}</span></h2>
            <div class="list">
                @foreach ($section['ideas'] as $idea)
                    <div id="idea-{{ $idea->id }}" class="row {{ in_array($status, ['wait', 'rethink'], true) ? 'wait' : ($status === 'done' ? 'done' : '') }}">
                        <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between">
                            <b>{{ $idea->title }}</b>
                            <span class="chip {{ in_array($status, ['wait', 'rethink'], true) ? 'w' : '' }}">{{ $section['label'] }}</span>
                        </div>
                        <div class="muted">{{ $idea->why }}</div>
                        <div class="meta">
                            <span>Asked by {{ $team->exists($idea->agent) ? $team->label($idea->agent) : $idea->agent }}</span>
                            <span>Size: {{ $idea->size }}</span>
                            @if ($idea->note)<span>{{ $idea->note }}</span>@endif
                        </div>

                        @if ($idea->owner_reason && in_array($status, ['wait', 'rethink', 'later'], true))
                            <div class="muted"><b>{{ $status === 'rethink' ? 'Your reason' : 'Your earlier reason' }}:</b> {{ $idea->owner_reason }}@if ($status === 'rethink') <span class="dots" aria-hidden="true"><i></i><i></i><i></i></span> waiting for {{ $team->exists($idea->agent) ? $team->label($idea->agent) : $idea->agent }} to rethink it (the answer is in your private chat)@endif</div>
                        @endif

                        @if ($status === 'yes' && ! $idea->note)
                            <div class="muted"><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span> The IT Manager is writing the requirement…</div>
                        @endif

                        @if ($idea->requirement)
                            <details @if($status === 'ready') open @endif>
                                <summary><b>Requirement</b> <span class="muted">written by the IT Manager</span></summary>
                                <div class="requirement">{!! \Illuminate\Support\Str::markdown($idea->requirement, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}</div>
                            </details>
                        @endif

                        @can('owner')
                            @php($said = $talk['idea:'.$idea->id] ?? 0)
                            @if ($idea->isOpenForDiscussion() || $said > 0)
                                <details class="thread" data-channel="idea:{{ $idea->id }}" data-open="{{ $idea->isOpenForDiscussion() ? 1 : 0 }}" @if($status === 'rethink') open @endif>
                                    <summary>{{ $idea->isOpenForDiscussion() ? 'Discuss with '.($team->exists($idea->agent) ? $team->label($idea->agent) : $idea->agent) : 'The discussion' }} <span class="muted">· {{ $said }}</span></summary>
                                    <div class="thread-list" aria-live="polite"><div class="muted">Loading…</div></div>
                                    <div class="thread-working muted" hidden><span class="dots" aria-hidden="true"><i></i><i></i><i></i></span> <span></span></div>
                                    @if ($idea->isOpenForDiscussion())
                                        <form class="thread-form">
                                            <textarea class="field" name="body" rows="2" maxlength="8000" required placeholder="Ask, push back, or agree a change… (Enter to send, Shift+Enter for a new line)"></textarea>
                                            <button class="btn" type="submit">Send</button>
                                        </form>
                                        <div class="muted small">When you agree, the agent updates the request here; close the topic with <b>Approve</b>. The IT Manager's requirement follows what you agreed.</div>
                                    @endif
                                </details>
                            @endif
                            <div class="inline-form">
                                @if (in_array($status, ['wait', 'rethink', 'later'], true))
                                    <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="yes"><button class="btn" type="submit">Approve</button></form>
                                @endif
                                @if ($status === 'wait')
                                    <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="later"><button class="btn ghost" type="submit">Not now</button></form>
                                @endif
                                @if (in_array($status, ['wait', 'later'], true))
                                    <details class="reconsider">
                                        <summary class="btn ghost">Reconsider</summary>
                                        <form method="POST" action="{{ route('ideas.reconsider', $idea) }}">@csrf
                                            <textarea class="field" name="reason" rows="3" maxlength="2000" required placeholder="Why can't this be done as asked? e.g. too costly now, the drivers won't fill it in, we already track this elsewhere…"></textarea>
                                            <button class="btn" type="submit">Send back with my reason</button>
                                        </form>
                                    </details>
                                @endif
                                @if ($status === 'ready')
                                    <button class="btn" type="button" data-copy="{{ $idea->forClaudeCode() }}">Copy for Claude Code</button>
                                    <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="building"><button class="btn ghost" type="submit">Being built</button></form>
                                @endif
                                @if (in_array($status, ['ready', 'building'], true))
                                    <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="done"><button class="btn ghost" type="submit">Mark done</button></form>
                                @endif
                                @if (in_array($status, ['yes', 'ready'], true))
                                    <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="rewrite"><button class="btn ghost" type="submit">Write it again</button></form>
                                @endif
                            </div>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach
@endsection
