@extends('layouts.app')

@section('title', $title ?? 'Settings')

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <style>
        .settings-shell { display: grid; gap: 1.25rem; }
        @media (min-width: 900px) {
            .settings-shell { grid-template-columns: 14rem 1fr; align-items: start; }
        }
        .settings-nav { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.75rem; }
        .settings-nav summary { cursor: pointer; font-weight: 600; list-style: none; }
        .settings-nav ul { list-style: none; margin: 0.75rem 0 0; padding: 0; display: grid; gap: 0.35rem; }
        .settings-nav a { display: block; padding: 0.4rem 0.55rem; border-radius: 0.375rem; text-decoration: none; color: #4b5563; }
        .settings-nav a[aria-current="page"] { background: #111827; color: #fff; }
        .settings-panel { display: grid; gap: 1rem; }
        .settings-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 1.25rem; }
        .settings-grid { display: grid; gap: 0.75rem; }
        @media (min-width: 700px) {
            .settings-grid--cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .settings-danger { border-color: #fecaca; background: #fff7f7; }
        .settings-status { margin-bottom: 1rem; }
        .settings-list { display: grid; gap: 0.75rem; }
        .settings-list-item { border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.85rem; background: #fff; }
        .settings-inline { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; }
        .settings-secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #111827; color: #f9fafb; padding: 0.75rem; border-radius: 0.375rem; word-break: break-all; }
        .settings-help { color: #6b7280; font-size: 0.875rem; }
        .settings-errors { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; border-radius: 0.375rem; padding: 0.75rem 1rem; margin-bottom: 1rem; }
    </style>
@endpush

@section('content')
    <div class="settings-shell">
        <nav class="settings-nav" aria-label="Settings">
            <details open>
                <summary>Settings</summary>
                <ul>
                    @foreach ($settingsNav as $item)
                        <li>
                            <a href="{{ route($item['route']) }}"
                               @if (($settingsSection ?? '') === $item['key']) aria-current="page" @endif>
                                {{ $item['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </details>
        </nav>

        <div class="settings-panel">
            @if (session('settingsStatus'))
                <div class="alert alert--success settings-status" role="status">{{ session('settingsStatus') }}</div>
            @endif

            @if ($errors->any())
                <div class="settings-errors" role="alert">
                    <strong>Please fix the following:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('settings')
        </div>
    </div>
@endsection
