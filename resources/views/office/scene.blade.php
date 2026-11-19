@extends('layouts.app', ['title' => '3D office · Wanaan Office'])

@push('head')
    @vite('resources/js/office3d.js')
@endpush

@section('content')
    <section class="scene-wrap">
        <div class="scene" id="scene" aria-label="The office in 3D: each agent at its desk, working when it really works">
            <div class="scene-fallback" id="scene-fallback" hidden>This screen cannot show 3D. <a href="{{ route('office') }}">Use the desks instead.</a></div>
        </div>
        <aside class="scene-side">
            <div class="panel scene-card" id="scene-card" hidden>
                <b data-name></b>
                <span class="muted" data-title></span>
                <p data-step style="margin:0"></p>
                <a class="btn" data-chat href="#">Talk</a>
                <button class="btn ghost" type="button" id="scene-home">Back to the whole office</button>
            </div>
            <div class="now" id="scene-now"><span class="now-l">Right now</span><span>Nobody is working.</span></div>
            <div class="panel scene-legend">
                <b>How to read it</b>
                <ul>
                    <li><span class="lg lg-work"></span> Typing, screen scrolling: working, its real step on the screen</li>
                    <li><span class="lg lg-idle"></span> Leaning back, dim logo: ready</li>
                    <li><span class="lg lg-stuck"></span> Orange screen: stuck</li>
                    <li><span class="lg lg-ask"></span> Paper plane between desks: one agent asked another</li>
                    <li><span class="lg lg-q"></span> Floating envelopes: questions waiting for you</li>
                    <li><span class="lg lg-coin"></span> Gold coins: this month's AI spending</li>
                    <li><span class="lg lg-meet"></span> Glowing meeting room: a team meeting; a beam marks whose turn it is</li>
                </ul>
                <p class="muted" style="margin:0">Click a desk to go to it, an envelope to answer. Drag to look around, scroll to zoom. Light follows Bahrain time.</p>
            </div>
        </aside>
    </section>
    <script type="application/json" id="scene-data">{!! json_encode($sceneData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
