<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Commonplace' }}</title>
    @if (! empty($description))
        <meta name="description" content="{{ $description }}">
    @endif
    <link rel="stylesheet" href="{{ route('commonplace.asset.css') }}">
    @stack('head')
</head>
<body class="commonplace">
    {{-- Header injection point: a consumer can `@section('commonplace.nav')`
         to replace the entire default topbar with their own (e.g. tying
         the vault into a global app nav). Leave empty to keep the
         default. --}}
    @hasSection('commonplace.nav')
        @yield('commonplace.nav')
    @else
        <header class="commonplace-topbar" role="banner">
            <nav class="commonplace-nav" aria-label="Commonplace navigation">
                <a class="commonplace-brand" href="{{ route('commonplace.index') }}">Commonplace</a>
                <ul class="commonplace-nav-links" role="list">
                    <li><a href="{{ route('commonplace.index') }}">Notes</a></li>
                    <li><a href="{{ route('commonplace.search') }}">Search</a></li>
                    <li><a href="{{ route('commonplace.graph') }}">Graph</a></li>
                    <li><a href="{{ route('commonplace.create') }}">New</a></li>
                </ul>
            </nav>
        </header>
    @endif

    <main class="commonplace-main" id="main" tabindex="-1">
        @if (session('success'))
            <div class="cp-alert cp-alert-success" role="status">
                {{ session('success') }}
            </div>
        @endif

        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
