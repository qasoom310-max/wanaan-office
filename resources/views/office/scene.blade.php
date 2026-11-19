@extends('layouts.app', ['title' => '3D office · Wanaan Office'])

@push('head')
    @vite('resources/js/office3d.js')
@endpush

@section('content')
    <section class="scene-wrap">
        <div class="scene" id="scene" aria-label="The office in 3D: one pod per agent, lit when it works">
            <div class="scene-fallback" id="scene-fallback" hidden>This screen cannot show 3D. <a href="{{ route('office') }}">Use the desks instead.</a></div>
        </div>
        <aside class="scene-side">
            <div class="now" id="scene-now"><span class="now-l">Right now</span><span>Nobody is working.</span></div>
            <div class="panel scene-legend">
                <b>How to read it</b>
                <ul>
                    <li><span class="lg lg-idle"></span> Ready: the pod breathes</li>
                    <li><span class="lg lg-work"></span> Working: lit, with a beam and its real step</li>
                    <li><span class="lg lg-wait"></span> Waiting for its turn in a meeting</li>
                    <li><span class="lg lg-stuck"></span> Stuck</li>
                    <li><span class="lg lg-ask"></span> A light between two pods: one asked the other</li>
                    <li><span class="lg lg-q"></span> Amber orbs: questions waiting for you</li>
                    <li>Column height: this month's AI spending</li>
                </ul>
                <p class="muted" style="margin:0">Click a pod to talk to that agent alone. Drag to look around, scroll to zoom.</p>
            </div>
        </aside>
    </section>
    <script type="application/json" id="scene-data">{!! json_encode($sceneData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection
