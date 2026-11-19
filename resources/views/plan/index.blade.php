@extends('layouts.app', ['title' => 'Website plan · Wanaan Office'])

@section('content')
    <div class="panel">
        <b>Gulf limousine booking website</b>
        <p class="muted" style="margin:0;max-width:75ch">Every page the IT Manager plans, one card each. Approve a page as it is, or ask for changes. Only approved pages are built, on a hidden address (password, not on Google) until you say it is ready.</p>
        <div class="meta">
            @foreach (\App\Models\PlanPage::STATUSES as $key => $label)
                <span>{{ $label }}: <b>{{ $counts[$key] ?? 0 }}</b></span>
            @endforeach
        </div>
        @if ($pages->isEmpty())
            <p class="muted" style="margin:0">No pages yet. Ask the IT Manager in its private chat: <i>“Plan the Gulf limousine booking website: every page, then put each one on the Website plan.”</i></p>
        @endif
    </div>

    <div class="list">
        @foreach ($pages as $page)
            <div class="row {{ $page->status === 'planned' || $page->status === 'changes' ? 'wait' : ($page->status === 'live' ? 'done' : '') }}">
                <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between">
                    <b>{{ $page->title }} @if($page->path)<span class="muted" style="font-weight:400">{{ $page->path }}</span>@endif</b>
                    <span class="chip {{ in_array($page->status, ['planned', 'changes'], true) ? 'w' : '' }}">{{ $page->statusName() }}</span>
                </div>
                <div><b>Why:</b> {{ $page->purpose }}</div>
                @if ($page->audience)<div class="muted">For: {{ $page->audience }}</div>@endif
                <details><summary class="muted">What is on the page</summary><div style="white-space:pre-wrap">{{ $page->content }}</div></details>
                @if ($page->owner_note)<div class="err" style="padding:6px 10px">Your note: {{ $page->owner_note }}</div>@endif
                @can('owner')
                    <div class="inline-form">
                        @if (in_array($page->status, ['planned', 'changes'], true))
                            <form method="POST" action="{{ route('plan.decide', $page) }}">@csrf<input type="hidden" name="decision" value="approve"><button class="btn" type="submit">Approve</button></form>
                        @endif
                        @if (! in_array($page->status, ['live'], true))
                            <form method="POST" action="{{ route('plan.decide', $page) }}" class="inline-form" style="flex:1">@csrf<input type="hidden" name="decision" value="changes">
                                <input class="field" name="note" placeholder="What should change?" aria-label="What should change" required>
                                <button class="btn ghost" type="submit">Ask for changes</button>
                            </form>
                        @endif
                        @if ($page->status === 'built')
                            <form method="POST" action="{{ route('plan.decide', $page) }}">@csrf<input type="hidden" name="decision" value="live"><button class="btn ghost" type="submit">Mark live</button></form>
                        @endif
                    </div>
                @endcan
            </div>
        @endforeach
    </div>
@endsection
