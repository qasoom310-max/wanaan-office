@extends('layouts.app', ['title' => 'Monthly reports · Wanaan Office'])

@section('content')
    <p class="muted" style="margin:0;max-width:75ch">Each manager's improvement report, one per month: what went well, what is wrong, what to improve, and what they need. Written on the 1st of each month about the month before; a manager may also write or update this month's when you ask.</p>

    @forelse ($months as $month => $reports)
        <h2 class="board-h">{{ $reports->first()->monthName() }}</h2>
        <div class="list">
            @foreach ($reports as $report)
                <a class="row" href="{{ route('reports.show', $report) }}" style="color:inherit;text-decoration:none">
                    <b>{{ $report->title }}</b>
                    <div class="meta"><span>{{ $team->exists($report->agent) ? $team->label($report->agent) : $report->agent }}</span><span>Updated {{ $report->updated_at->format('j M Y') }}</span><span>{{ number_format(str_word_count($report->body)) }} words</span></div>
                </a>
            @endforeach
        </div>
    @empty
        <div class="panel"><p class="muted" style="margin:0">No reports yet. They are written on the 1st of each month. To get one now, ask a manager in its private chat: <i>“Write your improvement report for this month.”</i></p></div>
    @endforelse
@endsection
