<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', 'Outbound Messages') - {{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        {{-- Minimal, dependency-free base styling so the pages remain usable
             even before `npm run build` has produced Tailwind output. --}}
        <style>
            :root { color-scheme: light; }
            body { font-family: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif; margin: 0; background: #f8fafc; color: #1b1b18; }
            a { color: inherit; }
            .app-shell { min-height: 100vh; display: flex; flex-direction: column; }
            .app-header { background: #fff; border-bottom: 1px solid #e5e7eb; }
            .app-header__inner { max-width: 72rem; margin: 0 auto; padding: 0.75rem 1.25rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
            .app-brand { font-weight: 600; text-decoration: none; }
            .app-nav { display: flex; align-items: center; gap: 1rem; font-size: 0.875rem; }
            .app-nav a { text-decoration: none; color: #4b5563; }
            .app-nav a:hover { color: #111827; }
            .app-nav__user { color: #6b7280; }
            .app-nav__logout { background: none; border: none; padding: 0; font: inherit; color: #4b5563; cursor: pointer; }
            .app-main { flex: 1; }
            .app-container { max-width: 72rem; margin: 0 auto; padding: 1.5rem 1.25rem; }
            .alert { border-radius: 0.375rem; padding: 0.75rem 1rem; font-size: 0.875rem; margin-bottom: 1rem; }
            .alert--success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
            .alert--error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
            .alert--warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
            .card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1.25rem; }
            table.table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
            table.table th, table.table td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #e5e7eb; }
            table.table th { color: #6b7280; font-weight: 500; }
            .badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
            .badge--queued, .badge--sending { background: #eff6ff; color: #1d4ed8; }
            .badge--sent { background: #f0fdf4; color: #15803d; }
            .badge--delivered { background: #ecfdf5; color: #047857; }
            .badge--failed { background: #fef2f2; color: #b91c1c; }
            .badge--cancelled { background: #f3f4f6; color: #374151; }
            .badge--draft { background: #f3f4f6; color: #374151; }
            .btn { display: inline-block; padding: 0.4rem 0.9rem; border-radius: 0.375rem; font-size: 0.875rem; border: 1px solid #d1d5db; background: #fff; color: #111827; cursor: pointer; text-decoration: none; }
            .btn:hover { border-color: #9ca3af; }
            .btn--primary { background: #111827; color: #fff; border-color: #111827; }
            .btn--danger { background: #fff; color: #b91c1c; border-color: #fecaca; }
            .form-field { margin-bottom: 1rem; }
            .form-field label { display: block; font-size: 0.875rem; margin-bottom: 0.25rem; color: #374151; }
            .form-field input, .form-field select { width: 100%; box-sizing: border-box; padding: 0.4rem 0.6rem; border: 1px solid #d1d5db; border-radius: 0.375rem; font: inherit; }
            .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: end; margin-bottom: 1.25rem; }
            .filters .form-field { margin-bottom: 0; min-width: 10rem; }
            .muted { color: #6b7280; }
            .stack { display: flex; flex-direction: column; gap: 0.75rem; }
            .row { display: flex; gap: 0.75rem; align-items: center; }
            .copy-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; background: #f3f4f6; padding: 0.15rem 0.4rem; border-radius: 0.25rem; }
            .html-body-frame { border: 1px solid #e5e7eb; border-radius: 0.375rem; padding: 1rem; background: #fff; overflow: auto; max-height: 32rem; }
            .pagination { display: flex; gap: 0.5rem; margin-top: 1rem; }
        </style>

        @stack('head')
    </head>
    <body>
        <div class="app-shell">
            <header class="app-header">
                <div class="app-header__inner">
                    <a href="{{ route('outbound-messages.index') }}" class="app-brand">{{ config('app.name', 'Laravel') }}</a>

                    @auth
                        <nav class="app-nav">
                            <a href="{{ route('outbound-messages.index') }}">Outbound Messages</a>
                            <a href="{{ route('outbound-drafts.index') }}">Drafts</a>
                            <span class="app-nav__user">{{ auth()->user()->email }}</span>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="app-nav__logout">Log out</button>
                            </form>
                        </nav>
                    @endauth
                </div>
            </header>

            <main class="app-main">
                <div class="app-container">
                    @if (session('outboundStatus'))
                        <div class="alert alert--success">{{ session('outboundStatus') }}</div>
                    @endif

                    @if (session('outboundError'))
                        <div class="alert alert--error">{{ session('outboundError') }}</div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert--error">
                            <ul style="margin:0; padding-left: 1.1rem;">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @yield('content')
                </div>
            </main>
        </div>
    </body>
</html>
