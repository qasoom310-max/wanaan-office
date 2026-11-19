@extends('layouts.app', ['title' => 'Questions · Wanaan Office'])

@section('content')
    <p class="muted" style="margin:0;max-width:70ch">What each agent needs from you to work well. Answer one and it becomes a fact the agent always reads.</p>

    <div class="list">
        @forelse ($open as $question)
            <div class="row q">
                <b>{{ $question->question }}</b>
                <div class="muted">{{ $question->why }}</div>
                <div class="meta"><span class="chip w">{{ $team->label($question->agent) }}</span></div>
                @can('owner')
                    <form class="inline-form" method="POST" action="{{ route('questions.answer', $question) }}">
                        @csrf
                        <input class="field" name="answer" maxlength="2000" placeholder="Your answer" aria-label="Answer" required>
                        <button class="btn" type="submit">Save as fact</button>
                    </form>
                @endcan
            </div>
        @empty
            <div class="empty">No open questions. Agents add new ones as they work.</div>
        @endforelse
    </div>

    @if ($answered->isNotEmpty())
        <h2 class="muted" style="font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin:8px 0 0">Answered</h2>
        <div class="list">
            @foreach ($answered as $question)
                <div class="row done">
                    <div><b>{{ $question->question }}</b><br>{{ $question->answer }}</div>
                    <div class="meta"><span>{{ $team->label($question->agent) }}</span><span>{{ $question->answered_at->format('j M Y') }}</span></div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
