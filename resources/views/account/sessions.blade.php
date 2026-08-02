@extends('layouts.app')

@section('title', 'Sessions')
@section('mailTitle', 'Sessions')
@section('mailNav', 'sessions')

@section('content')
    <div class="mail-page">
    <div class="card">
        <h1 style="margin-top:0;">Active sessions</h1>

        @if (session('identityStatus'))
            <div class="alert alert--success">{{ session('identityStatus') }}</div>
        @endif

        @unless ($enumerationSupported)
            <div class="alert alert--warning">Session enumeration is limited with the current session driver. Use database sessions for full management.</div>
        @endunless

        <table class="table">
            <thead>
                <tr>
                    <th>Session</th>
                    <th>Device</th>
                    <th>Location</th>
                    <th>Last activity</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sessions as $session)
                    <tr>
                        <td>
                            <span class="copy-id">{{ $session['id_masked'] }}</span>
                            @if ($session['is_current'])
                                <span class="badge badge--sent">Current</span>
                            @endif
                        </td>
                        <td>{{ $session['user_agent'] ?? 'Unknown' }}</td>
                        <td>{{ $session['ip_address'] ?? 'Unknown' }}</td>
                        <td>{{ $session['last_activity_at'] ?? '—' }}</td>
                        <td>
                            @unless ($session['is_current'])
                                <form method="POST" action="{{ route('account.sessions.destroy', $session['id']) }}" class="stack">
                                    @csrf
                                    @method('DELETE')
                                    <input type="password" name="password" placeholder="Confirm password" required>
                                    <button type="submit" class="btn btn--danger">Revoke</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No sessions listed.</td></tr>
                @endforelse
            </tbody>
        </table>

        <form method="POST" action="{{ route('account.sessions.destroy-others') }}" style="margin-top:1rem;">
            @csrf
            @method('DELETE')
            <div class="form-field">
                <label for="password">Confirm password to revoke all other sessions</label>
                <input id="password" type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn--danger">Revoke other sessions</button>
        </form>
    </div>
    </div>
@endsection
