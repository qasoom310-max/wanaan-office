@extends('layouts.app', ['title' => 'IT board · Wanaan Office'])

@section('content')
    <p class="muted" style="margin:0;max-width:70ch">Improvements the team asks IT for. Nothing is built until the owner approves it.</p>

    <div class="list">
        @foreach ($ideas as $idea)
            <div class="row {{ $idea->status }}">
                <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between">
                    <b>{{ $idea->title }}</b>
                    <span class="chip {{ $idea->status === 'wait' ? 'w' : '' }}">{{ \App\Models\Idea::STATUSES[$idea->status] }}</span>
                </div>
                <div class="muted">{{ $idea->why }}</div>
                <div class="meta">
                    <span>Asked by {{ $team->exists($idea->agent) ? $team->label($idea->agent) : $idea->agent }}</span>
                    <span>Size: {{ $idea->size }}</span>
                    @if ($idea->note)<span>{{ $idea->note }}</span>@endif
                </div>
                @can('owner')
                    @if (in_array($idea->status, ['wait', 'later'], true))
                        <div class="inline-form">
                            <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="yes"><button class="btn" type="submit">Approve</button></form>
                            @if ($idea->status === 'wait')
                                <form method="POST" action="{{ route('ideas.decide', $idea) }}">@csrf<input type="hidden" name="status" value="later"><button class="btn ghost" type="submit">Not now</button></form>
                            @endif
                        </div>
                    @endif
                @endcan
            </div>
        @endforeach
    </div>
@endsection
