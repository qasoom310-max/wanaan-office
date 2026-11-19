@extends('layouts.app', ['title' => $report->title.' · Wanaan Office'])

@section('content')
    <div class="inline-form no-print">
        <a class="btn ghost" href="{{ route('reports') }}">← All reports</a>
        <a class="btn ghost" href="{{ route('reports.download', $report) }}">Download</a>
        <button class="btn ghost" type="button" onclick="window.print()">Print</button>
    </div>
    <article class="report">
        <h1>{{ $report->title }}</h1>
        <p class="muted">{{ $team->exists($report->agent) ? $team->label($report->agent) : $report->agent }} · {{ $report->monthName() }} · updated {{ $report->updated_at->format('j M Y H:i') }}</p>
        {!! \Illuminate\Support\Str::markdown($report->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    </article>
@endsection
