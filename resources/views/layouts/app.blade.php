<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Wanaan Office' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
<div class="wrap">
    @auth
        @php
            $openQuestions = \App\Models\Question::whereNull('answered_at')->count();
            $waitingIdeas = \App\Models\Idea::where('status', 'wait')->count();
        @endphp
        <header class="top">
            <div class="brand">Wanaan Office <small>Your AI team, one desk each</small></div>
            <nav class="tabs" aria-label="Pages">
                <a href="{{ route('office') }}" @if(request()->routeIs('office')) aria-current="page" @endif>Office</a>
                <a href="{{ route('office.3d') }}" @if(request()->routeIs('office.3d')) aria-current="page" @endif>3D office</a>
                <a href="{{ route('facts') }}" @if(request()->routeIs('facts')) aria-current="page" @endif>Facts</a>
                <a href="{{ route('questions') }}" @if(request()->routeIs('questions')) aria-current="page" @endif>Questions @if($openQuestions)<span class="n">{{ $openQuestions }}</span>@endif</a>
                <a href="{{ route('ideas') }}" @if(request()->routeIs('ideas')) aria-current="page" @endif>IT board @if($waitingIdeas)<span class="n">{{ $waitingIdeas }}</span>@endif</a>
                @php $planWaiting = \App\Models\PlanPage::where('status', 'planned')->count(); @endphp
                <a href="{{ route('plan') }}" @if(request()->routeIs('plan')) aria-current="page" @endif>Website plan @if($planWaiting)<span class="n">{{ $planWaiting }}</span>@endif</a>
                <a href="{{ route('reports') }}" @if(request()->routeIs('reports*')) aria-current="page" @endif>Monthly reports</a>
                @can('owner')
                    <a href="{{ route('settings') }}" @if(request()->routeIs('settings')) aria-current="page" @endif>Settings</a>
                @endcan
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
            </nav>
        </header>
    @endauth

    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="err" role="alert">{{ $errors->first() }}</div>
    @endif

    {{ $slot ?? '' }}
    @yield('content')
</div>
</body>
</html>
