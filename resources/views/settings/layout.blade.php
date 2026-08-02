@extends('layouts.app')

@section('title', $title ?? 'Settings')
@section('mailTitle', $title ?? 'Settings')
@section('mailNav', 'settings')

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <style>
        .settings-shell { display: grid; gap: 1.25rem; padding: 1.25rem; }
        @media (min-width: 900px) {
            .settings-shell { grid-template-columns: 14rem 1fr; align-items: start; }
        }
        .settings-nav { background: #1c1c1f; border: 1px solid #2a2a2e; border-radius: 0.5rem; padding: 0.75rem; }
        .settings-nav summary { cursor: pointer; font-weight: 600; list-style: none; color: #f4f4f5; }
        .settings-nav ul { list-style: none; margin: 0.75rem 0 0; padding: 0; display: grid; gap: 0.35rem; }
        .settings-nav a { display: block; padding: 0.4rem 0.55rem; border-radius: 0.375rem; text-decoration: none; color: #9a9aa3; }
        .settings-nav a[aria-current="page"] { background: #3b82f6; color: #fff; }
        .settings-panel { display: grid; gap: 1rem; }
        .settings-card { background: #1c1c1f; border: 1px solid #2a2a2e; border-radius: 0.5rem; padding: 1.25rem; color: #f4f4f5; }
        .settings-grid { display: grid; gap: 0.75rem; }
        @media (min-width: 700px) {
            .settings-grid--cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .settings-danger { border-color: rgba(239,68,68,0.35); background: rgba(239,68,68,0.08); }
        .settings-status { margin-bottom: 1rem; }
        .settings-list { display: grid; gap: 0.75rem; }
        .settings-list-item { border: 1px solid #2a2a2e; border-radius: 0.5rem; padding: 0.85rem; background: #161618; }
        .settings-inline { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; }
        .settings-secret { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; background: #0b0b0c; color: #f9fafb; padding: 0.75rem; border-radius: 0.375rem; word-break: break-all; }
        .settings-help { color: #9a9aa3; font-size: 0.875rem; }
        .settings-errors { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; border-radius: 0.375rem; padding: 0.75rem 1rem; margin-bottom: 1rem; }
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
