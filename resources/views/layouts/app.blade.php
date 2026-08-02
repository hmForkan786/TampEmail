<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title', 'Inbox') - {{ config('app.name', 'Temail') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700" rel="stylesheet" />

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif

        <style>
            :root {
                color-scheme: dark;
                --mail-bg: #0b0b0c;
                --mail-sidebar: #0b0b0c;
                --mail-rail: #121214;
                --mail-panel: #161618;
                --mail-elevated: #1c1c1f;
                --mail-card: #232328;
                --mail-border: #2a2a2e;
                --mail-text: #f4f4f5;
                --mail-muted: #9a9aa3;
                --mail-faint: #6b6b74;
                --mail-blue: #3b82f6;
                --mail-blue-hover: #2563eb;
                --mail-yellow: #f5c518;
                --mail-danger: #3b82f6;
                --mail-badge: #ef4444;
                --mail-radius: 12px;
                --mail-sidebar-w: 15.5rem;
                --mail-rail-w: 17.5rem;
            }

            * { box-sizing: border-box; }
            html, body { height: 100%; }
            body {
                margin: 0;
                font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif;
                background: var(--mail-bg);
                color: var(--mail-text);
                -webkit-font-smoothing: antialiased;
            }
            a { color: inherit; text-decoration: none; }
            button { font: inherit; color: inherit; }
            svg { display: block; }

            .mail-app {
                min-height: 100vh;
                display: grid;
                grid-template-columns: var(--mail-sidebar-w) minmax(0, 1fr);
            }

            /* ── Sidebar ── */
            .mail-sidebar {
                background: var(--mail-sidebar);
                border-right: 1px solid var(--mail-border);
                padding: 1rem 0.75rem;
                display: flex;
                flex-direction: column;
                gap: 1rem;
                min-height: 100vh;
            }
            .mail-sidebar__nav {
                display: flex;
                flex-direction: column;
                gap: 0.15rem;
                flex: 1;
                overflow-y: auto;
                min-height: 0;
            }
            .mail-nav-link {
                display: flex;
                align-items: center;
                gap: 0.7rem;
                padding: 0.58rem 0.75rem;
                border-radius: 10px;
                color: var(--mail-text);
                font-size: 0.86rem;
                font-weight: 500;
                transition: background 0.15s ease;
                white-space: nowrap;
            }
            .mail-nav-link span {
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .mail-nav-link__badge {
                margin-left: auto;
                min-width: 1.25rem;
                height: 1.25rem;
                padding: 0 0.35rem;
                border-radius: 999px;
                background: var(--mail-badge);
                color: #fff;
                font-size: 0.7rem;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                justify-content: center;
            }
            .mail-sidebar__footer {
                border-top: 1px solid var(--mail-border);
                padding-top: 0.75rem;
                display: flex;
                flex-direction: column;
                gap: 0.45rem;
            }
            .mail-sidebar__user {
                font-size: 0.75rem;
                color: var(--mail-muted);
                padding: 0 0.35rem;
                word-break: break-all;
            }
            .mail-sidebar__logout {
                display: inline-flex;
                align-items: center;
                gap: 0.55rem;
                width: 100%;
                padding: 0.55rem 0.75rem;
                border-radius: 10px;
                border: 1px solid var(--mail-border);
                background: transparent;
                color: var(--mail-muted);
                cursor: pointer;
                font-size: 0.85rem;
                font-weight: 500;
            }
            .mail-sidebar__logout:hover {
                background: rgba(255,255,255,0.05);
                color: var(--mail-text);
            }
            .mail-sidebar__logout svg { width: 1.05rem; height: 1.05rem; }
            .mail-sidebar__brand {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.55rem;
                width: 100%;
                padding: 0.7rem 0.85rem;
                border-radius: 10px;
                background: var(--mail-blue);
                color: #fff;
                font-weight: 600;
                font-size: 0.95rem;
                border: none;
                cursor: pointer;
                transition: background 0.15s ease, transform 0.15s ease;
            }
            .mail-sidebar__brand:hover { background: var(--mail-blue-hover); }
            .mail-sidebar__brand:active { transform: scale(0.98); }
            .mail-nav-link svg { width: 1.15rem; height: 1.15rem; opacity: 0.9; flex-shrink: 0; }
            .mail-nav-link:hover { background: rgba(255,255,255,0.05); }
            .mail-nav-link.is-active { background: #252528; }

            /* ── Main column ── */
            .mail-main {
                min-width: 0;
                min-height: 100vh;
                display: flex;
                flex-direction: column;
                background: var(--mail-panel);
            }
            .mail-topbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 1rem;
                padding: 0.85rem 1.25rem;
                border-bottom: 1px solid var(--mail-border);
                background: var(--mail-panel);
            }
            .mail-topbar__left {
                display: flex;
                align-items: center;
                gap: 0.75rem;
            }
            .mail-topbar__title {
                margin: 0;
                font-size: 1.15rem;
                font-weight: 600;
                letter-spacing: -0.01em;
            }
            .mail-icon-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 2rem;
                height: 2rem;
                border-radius: 8px;
                border: none;
                background: transparent;
                color: var(--mail-text);
                cursor: pointer;
                transition: background 0.15s ease;
            }
            .mail-icon-btn:hover { background: rgba(255,255,255,0.06); }
            .mail-icon-btn svg { width: 1.2rem; height: 1.2rem; }
            .mail-compose {
                width: 2.15rem;
                height: 2.15rem;
                border-radius: 999px;
                border: none;
                background: var(--mail-blue);
                color: #fff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: background 0.15s ease, transform 0.15s ease;
            }
            .mail-compose:hover { background: var(--mail-blue-hover); }
            .mail-compose:active { transform: scale(0.96); }
            .mail-compose svg { width: 1rem; height: 1rem; }
            .mail-topbar__right {
                display: flex;
                align-items: center;
                gap: 0.35rem;
            }
            .mail-notify {
                position: relative;
            }
            .mail-notify__badge {
                position: absolute;
                top: 2px;
                right: 2px;
                width: 8px;
                height: 8px;
                border-radius: 999px;
                background: var(--mail-badge);
                border: 2px solid var(--mail-panel);
            }
            .mail-avatar {
                width: 2rem;
                height: 2rem;
                border-radius: 999px;
                background: #2a2a30;
                color: #fff;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                font-size: 0.8rem;
                font-weight: 600;
                border: 1px solid var(--mail-border);
            }

            .mail-body {
                flex: 1;
                min-height: 0;
                display: grid;
                grid-template-columns: minmax(0, 1fr);
            }
            .mail-body.has-rail {
                grid-template-columns: var(--mail-rail-w) minmax(0, 1fr);
            }

            /* ── Middle rail ── */
            .mail-rail {
                background: var(--mail-rail);
                border-right: 1px solid var(--mail-border);
                min-height: 0;
                overflow: auto;
                padding: 0.85rem 0.75rem;
                display: flex;
                flex-direction: column;
                gap: 0.75rem;
            }
            .mail-rail__tools {
                display: flex;
                align-items: center;
                gap: 0.15rem;
            }
            .mail-account {
                display: block;
                padding: 0.9rem 0.85rem 0.7rem;
                border-radius: 12px;
                background: var(--mail-card);
                transition: background 0.15s ease, box-shadow 0.15s ease;
            }
            .mail-account:hover { background: #2a2a30; }
            .mail-account.is-selected {
                box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.55);
                background: #2a2a32;
            }
            .mail-account__address {
                display: block;
                font-size: 0.9rem;
                font-weight: 500;
                word-break: break-all;
                margin-bottom: 0.85rem;
            }
            .mail-account__actions {
                display: flex;
                align-items: center;
                justify-content: space-between;
            }
            .mail-account__actions-left,
            .mail-account__actions-right {
                display: flex;
                align-items: center;
                gap: 0.35rem;
            }
            .mail-account__actions .mail-icon-btn { width: 1.75rem; height: 1.75rem; }
            .mail-account__actions .mail-icon-btn svg { width: 1rem; height: 1rem; }
            .mail-account__actions .is-mail svg { color: var(--mail-yellow); }
            .mail-account__actions .is-trash svg { color: var(--mail-blue); }
            .mail-rail__end {
                text-align: center;
                color: var(--mail-faint);
                font-size: 0.8rem;
                padding: 0.5rem 0 1rem;
            }

            /* ── Content panel ── */
            .mail-content {
                min-width: 0;
                min-height: 0;
                overflow: auto;
                background: var(--mail-panel);
                display: flex;
                flex-direction: column;
            }
            .mail-toolbar {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 0.5rem;
                padding: 0.55rem 1rem;
                border-bottom: 1px solid var(--mail-border);
            }
            .mail-toolbar__left,
            .mail-toolbar__right {
                display: flex;
                align-items: center;
                gap: 0.2rem;
            }
            .mail-check {
                width: 1rem;
                height: 1rem;
                accent-color: var(--mail-blue);
                margin: 0 0.35rem 0 0.15rem;
            }

            .mail-flash {
                margin: 0.85rem 1rem 0;
            }
            .alert {
                border-radius: 0.5rem;
                padding: 0.75rem 1rem;
                font-size: 0.875rem;
                margin-bottom: 0.75rem;
            }
            .alert--success { background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.35); color: #6ee7b7; }
            .alert--error { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; }
            .alert--warning { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.35); color: #fcd34d; }

            .mail-empty {
                flex: 1;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 1rem;
                padding: 2rem;
                color: var(--mail-muted);
                text-align: center;
            }
            .mail-empty__art {
                width: 7.5rem;
                height: 6.5rem;
                position: relative;
                opacity: 0.85;
            }
            .mail-empty__box {
                position: absolute;
                width: 4.2rem;
                height: 3.4rem;
                border: 2px solid #4b4b52;
                border-radius: 4px;
                background: linear-gradient(180deg, #2a2a30 0%, #1a1a1e 100%);
            }
            .mail-empty__box::before {
                content: '';
                position: absolute;
                left: -2px;
                right: -2px;
                top: -0.55rem;
                height: 0.7rem;
                border: 2px solid #4b4b52;
                border-bottom: none;
                border-radius: 3px 3px 0 0;
                background: #323238;
                clip-path: polygon(8% 100%, 28% 0, 72% 0, 92% 100%);
            }
            .mail-empty__box--back { left: 0.35rem; top: 0.85rem; opacity: 0.55; transform: scale(0.92); }
            .mail-empty__box--front { right: 0.2rem; top: 1.55rem; }
            .mail-empty h2 {
                margin: 0;
                font-size: 1rem;
                font-weight: 500;
                color: var(--mail-muted);
            }
            .mail-empty p { margin: 0; font-size: 0.85rem; color: var(--mail-faint); max-width: 18rem; }

            .mail-rows { display: flex; flex-direction: column; }
            .mail-row {
                display: grid;
                grid-template-columns: minmax(8rem, 12rem) minmax(0, 1fr) auto;
                gap: 0.75rem;
                align-items: center;
                padding: 0.85rem 1.1rem;
                border-bottom: 1px solid rgba(42,42,46,0.7);
                transition: background 0.12s ease;
            }
            .mail-row:hover { background: rgba(255,255,255,0.03); }
            .mail-row.is-selected { background: rgba(59,130,246,0.08); }
            .mail-row.is-unread .mail-row__sender,
            .mail-row.is-unread .mail-row__subject { font-weight: 600; color: #fff; }
            .mail-row__sender { font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .mail-row__subject { font-size: 0.88rem; color: var(--mail-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .mail-row time { font-size: 0.78rem; color: var(--mail-faint); white-space: nowrap; }

            .mail-preview {
                border-top: 1px solid var(--mail-border);
                padding: 1.25rem 1.35rem 2rem;
            }
            .mail-preview h1 {
                margin: 0 0 0.75rem;
                font-size: 1.25rem;
                font-weight: 600;
            }
            .mail-preview__meta {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem 1.25rem;
                color: var(--mail-muted);
                font-size: 0.85rem;
                margin-bottom: 1.25rem;
            }
            .mail-preview__body {
                white-space: pre-wrap;
                line-height: 1.55;
                color: #d4d4d8;
                font-size: 0.95rem;
            }

            .mail-page {
                padding: 1.25rem;
            }
            .mail-page h1 { margin: 0 0 0.35rem; font-size: 1.35rem; }
            .muted { color: var(--mail-muted); }
            .card {
                background: var(--mail-elevated);
                border: 1px solid var(--mail-border);
                border-radius: var(--mail-radius);
                padding: 1.15rem;
            }
            .btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 0.35rem;
                padding: 0.45rem 0.9rem;
                border-radius: 0.5rem;
                font-size: 0.875rem;
                border: 1px solid var(--mail-border);
                background: var(--mail-elevated);
                color: var(--mail-text);
                cursor: pointer;
            }
            .btn:hover { border-color: #3f3f46; }
            .btn--primary { background: var(--mail-blue); border-color: var(--mail-blue); color: #fff; }
            .btn--primary:hover { background: var(--mail-blue-hover); }
            .btn--danger { color: #fca5a5; border-color: rgba(239,68,68,0.35); }
            .form-field { margin-bottom: 1rem; }
            .form-field label { display: block; font-size: 0.875rem; margin-bottom: 0.25rem; color: var(--mail-muted); }
            .form-field input, .form-field select, .form-field textarea {
                width: 100%;
                box-sizing: border-box;
                padding: 0.45rem 0.65rem;
                border: 1px solid var(--mail-border);
                border-radius: 0.5rem;
                font: inherit;
                background: #111113;
                color: var(--mail-text);
            }
            .filters { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: end; margin-bottom: 1.25rem; }
            .filters .form-field { margin-bottom: 0; min-width: 10rem; }
            table.table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
            table.table th, table.table td { text-align: left; padding: 0.55rem 0.75rem; border-bottom: 1px solid var(--mail-border); }
            table.table th { color: var(--mail-muted); font-weight: 500; }
            .badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 500; }
            .badge--queued, .badge--sending { background: rgba(59,130,246,0.15); color: #93c5fd; }
            .badge--sent, .badge--delivered { background: rgba(16,185,129,0.15); color: #6ee7b7; }
            .badge--failed { background: rgba(239,68,68,0.15); color: #fca5a5; }
            .badge--cancelled, .badge--draft { background: rgba(161,161,170,0.15); color: #d4d4d8; }
            .badge--scheduled { background: rgba(245,158,11,0.15); color: #fcd34d; }
            .row { display: flex; gap: 0.75rem; align-items: center; }
            .stack { display: flex; flex-direction: column; gap: 0.75rem; }
            .pagination { display: flex; gap: 0.5rem; margin: 1rem; flex-wrap: wrap; }
            .pagination a, .pagination span {
                padding: 0.3rem 0.55rem;
                border-radius: 0.35rem;
                border: 1px solid var(--mail-border);
                color: var(--mail-muted);
                font-size: 0.8rem;
            }
            .copy-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8125rem; background: #111113; padding: 0.15rem 0.4rem; border-radius: 0.25rem; }
            .html-body-frame { border: 1px solid var(--mail-border); border-radius: 0.375rem; padding: 1rem; background: #111113; overflow: auto; max-height: 32rem; }
            .commercial-usage-banner {
                margin: 0.75rem 1rem 0;
                padding: 0.65rem 0.85rem;
                border-radius: 0.5rem;
                background: rgba(59,130,246,0.08);
                border: 1px solid rgba(59,130,246,0.25);
                color: #93c5fd;
                font-size: 0.85rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
            }

            .auth-shell {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                background: var(--mail-bg);
            }
            .auth-shell .mail-page,
            .auth-shell > .card {
                width: 100%;
            }

            @media (max-width: 960px) {
                .mail-app { grid-template-columns: 1fr; }
                .mail-sidebar {
                    flex-direction: row;
                    align-items: center;
                    overflow-x: auto;
                    gap: 0.5rem;
                    padding: 0.65rem;
                    border-right: none;
                    border-bottom: 1px solid var(--mail-border);
                }
                .mail-sidebar__nav { flex-direction: row; }
                .mail-sidebar__brand { width: auto; flex-shrink: 0; }
                .mail-nav-link span { display: none; }
                .mail-body { grid-template-columns: 1fr !important; }
                .mail-rail { border-right: none; border-bottom: 1px solid var(--mail-border); max-height: 40vh; }
                .mail-row { grid-template-columns: 1fr auto; }
                .mail-row__subject { grid-column: 1 / -1; }
            }
        </style>

        @stack('head')
    </head>
    <body>
        @guest
            <div class="auth-shell">
                <div style="width:100%; max-width: 28rem;">
                    @if (session('identityStatus'))
                        <div class="alert alert--success">{{ session('identityStatus') }}</div>
                    @endif
                    @if (isset($errors) && $errors->any())
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
            </div>
        @else
        @php
            $mailNav = trim($__env->yieldContent('mailNav', ''));
            if ($mailNav === '') {
                $mailNav = match (true) {
                    request()->routeIs('mailbox.*') => 'inbox',
                    request()->routeIs('outbound-messages.*') && request('state') === 'scheduled' => 'scheduled',
                    request()->routeIs('outbound-messages.*') => 'outbound',
                    request()->routeIs('outbound-drafts.*') => 'drafts',
                    request()->routeIs('outbound-sender-profiles.*') => 'sender-profiles',
                    request()->routeIs('outbound-notifications.*') => 'notifications',
                    request()->routeIs('outbound-notification-preferences.*') => 'notification-preferences',
                    request()->routeIs('settings.*') => 'settings',
                    request()->routeIs('account.security') => 'security',
                    request()->routeIs('account.sessions', 'account.sessions.*') => 'sessions',
                    default => '',
                };
            }
            $mailTitle = trim($__env->yieldContent('mailTitle', ''));
            if ($mailTitle === '') {
                $mailTitle = trim($__env->yieldContent('title', 'Mail'));
            }
            $userInitial = strtoupper(substr((string) (auth()->user()?->email ?? 'U'), 0, 1));
            $outboundUnreadCount = \App\Models\OutboundNotification::query()
                ->where('user_id', auth()->id())
                ->whereNull('read_at')
                ->whereNull('dismissed_at')
                ->count();
        @endphp

        <div class="mail-app">
            <aside class="mail-sidebar" aria-label="App navigation">
                <a href="{{ route('mailbox.index') }}" class="mail-sidebar__brand">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" width="18" height="18">
                        <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                    </svg>
                    <span>{{ config('app.name', 'Temail') }}</span>
                </a>

                <nav class="mail-sidebar__nav">
                    <a href="{{ route('mailbox.index') }}" class="mail-nav-link {{ $mailNav === 'inbox' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                        <span>Inbox</span>
                    </a>
                    <a href="{{ route('outbound-messages.index') }}" class="mail-nav-link {{ $mailNav === 'outbound' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"></path><path d="M22 2 11 13"></path></svg>
                        <span>Outbound Messages</span>
                    </a>
                    <a href="{{ route('outbound-drafts.index') }}" class="mail-nav-link {{ $mailNav === 'drafts' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>
                        <span>Drafts</span>
                    </a>
                    <a href="{{ route('outbound-messages.index', ['state' => 'scheduled']) }}" class="mail-nav-link {{ $mailNav === 'scheduled' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <span>Scheduled</span>
                    </a>
                    <a href="{{ route('outbound-sender-profiles.index') }}" class="mail-nav-link {{ $mailNav === 'sender-profiles' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                        <span>Sender Profiles</span>
                    </a>
                    <a href="{{ route('outbound-notifications.index') }}" class="mail-nav-link {{ $mailNav === 'notifications' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path></svg>
                        <span>Notifications</span>
                        @if ($outboundUnreadCount > 0)
                            <span class="mail-nav-link__badge">{{ $outboundUnreadCount > 99 ? '99+' : $outboundUnreadCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('outbound-notification-preferences.index') }}" class="mail-nav-link {{ $mailNav === 'notification-preferences' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        <span>Notification Preferences</span>
                    </a>
                    <a href="{{ route('settings.index') }}" class="mail-nav-link {{ $mailNav === 'settings' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M3 12h3m12 0h3M12 3v3m0 12v3"></path></svg>
                        <span>Settings</span>
                    </a>
                    <a href="{{ route('account.security') }}" class="mail-nav-link {{ $mailNav === 'security' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                        <span>Security</span>
                    </a>
                    <a href="{{ route('account.sessions') }}" class="mail-nav-link {{ $mailNav === 'sessions' ? 'is-active' : '' }}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="3" rx="2"></rect><line x1="8" x2="16" y1="21" y2="21"></line><line x1="12" x2="12" y1="17" y2="21"></line></svg>
                        <span>Sessions</span>
                    </a>
                </nav>

                <div class="mail-sidebar__footer">
                    <div class="mail-sidebar__user">{{ auth()->user()?->email }}</div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="mail-sidebar__logout">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" x2="9" y1="12" y2="12"></line></svg>
                            Log out
                        </button>
                    </form>
                </div>
            </aside>

            <div class="mail-main">
                <header class="mail-topbar">
                    <div class="mail-topbar__left">
                        <button type="button" class="mail-icon-btn" aria-label="Menu" onclick="document.body.classList.toggle('mail-nav-collapsed')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" x2="20" y1="12" y2="12"></line><line x1="4" x2="20" y1="6" y2="6"></line><line x1="4" x2="20" y1="18" y2="18"></line></svg>
                        </button>
                        <h1 class="mail-topbar__title">{{ $mailTitle }}</h1>
                        <a href="{{ route('outbound-drafts.compose') }}" class="mail-compose" aria-label="Compose" title="Compose">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"></path><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"></path></svg>
                        </a>
                    </div>
                    <div class="mail-topbar__right">
                        <button type="button" class="mail-icon-btn" aria-label="Theme" title="Theme">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"></circle><path d="M12 2v2"></path><path d="M12 20v2"></path><path d="m4.93 4.93 1.41 1.41"></path><path d="m17.66 17.66 1.41 1.41"></path><path d="M2 12h2"></path><path d="M20 12h2"></path><path d="m6.34 17.66-1.41 1.41"></path><path d="m19.07 4.93-1.41 1.41"></path></svg>
                        </button>
                        <a href="{{ route('outbound-notifications.index') }}" class="mail-icon-btn mail-notify" aria-label="Notifications">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 18-5v12L3 14v-3z"></path><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path></svg>
                            @if ($outboundUnreadCount > 0)
                                <span class="mail-notify__badge" aria-hidden="true"></span>
                            @endif
                        </a>
                        <a href="{{ route('settings.profile') }}" class="mail-avatar" title="{{ auth()->user()?->email }}">{{ $userInitial }}</a>
                    </div>
                </header>

                @if (session('outboundStatus') || session('identityStatus') || session('outboundError') || (isset($errors) && $errors->any()))
                    <div class="mail-flash">
                        @if (session('outboundStatus'))
                            <div class="alert alert--success">{{ session('outboundStatus') }}</div>
                        @endif
                        @if (session('identityStatus'))
                            <div class="alert alert--success">{{ session('identityStatus') }}</div>
                        @endif
                        @if (session('outboundError'))
                            <div class="alert alert--error">{{ session('outboundError') }}</div>
                        @endif
                        @if (isset($errors) && $errors->any())
                            <div class="alert alert--error">
                                <ul style="margin:0; padding-left: 1.1rem;">
                                    @foreach ($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="mail-body {{ $__env->hasSection('mailRail') ? 'has-rail' : '' }}">
                    @hasSection('mailRail')
                        <aside class="mail-rail" aria-label="Accounts">
                            @yield('mailRail')
                        </aside>
                    @endif

                    <div class="mail-content">
                        @yield('content')
                    </div>
                </div>
            </div>
        </div>
        @endguest
    </body>
</html>
