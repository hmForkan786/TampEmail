@extends('settings.layout')

@section('title', 'Sessions')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Sessions</h1>
        @unless ($enumerationSupported)
            <p class="alert alert--warning">Session listing requires the database session driver. Destructive revoke actions are unavailable on this environment.</p>
        @endunless

        <div class="settings-list" role="list">
            @forelse ($sessions as $session)
                <div class="settings-list-item" role="listitem">
                    <p><strong>{{ $session['is_current'] ? 'Current session' : 'Other session' }}</strong></p>
                    <p class="settings-help">Masked id: {{ $session['id_masked'] }}</p>
                    <p class="settings-help">Device: {{ $session['user_agent'] ?? 'Unknown' }}</p>
                    <p class="settings-help">Approx location: {{ $session['ip_address'] ?? 'Unknown' }}</p>
                    <p class="settings-help">Last activity: {{ $session['last_activity_at'] ?? 'n/a' }}</p>
                    @unless ($session['is_current'])
                        <form method="POST" action="{{ route('settings.sessions.destroy', $session['id']) }}" class="settings-inline">
                            @csrf
                            @method('DELETE')
                            <div class="form-field">
                                <label for="password-{{ $session['id_masked'] }}">Password</label>
                                <input id="password-{{ $session['id_masked'] }}" name="password" type="password" required>
                            </div>
                            <button class="btn btn--danger" type="submit">Revoke</button>
                        </form>
                    @endunless
                </div>
            @empty
                <p class="settings-help">No sessions to list.</p>
            @endforelse
        </div>

        @if ($enumerationSupported)
            <form method="POST" action="{{ route('settings.sessions.destroy-others') }}" class="settings-inline" style="margin-top:1rem;">
                @csrf
                @method('DELETE')
                <div class="form-field">
                    <label for="password_others">Password</label>
                    <input id="password_others" name="password" type="password" required>
                </div>
                <button class="btn btn--danger" type="submit">Revoke all other sessions</button>
            </form>
        @endif
    </div>

    <div class="settings-card">
        <h2>Login history</h2>
        <p class="settings-help">Raw IP and user-agent are not shown. Retention: {{ $loginHistoryRetentionDays }} days. History is immutable.</p>

        <form method="GET" class="filters">
            <div class="form-field">
                <label for="success">Result</label>
                <select id="success" name="success">
                    <option value="">All</option>
                    <option value="1" @selected(request('success') === '1')>Success</option>
                    <option value="0" @selected(request('success') === '0')>Failure</option>
                </select>
            </div>
            <div class="form-field">
                <label for="from">From</label>
                <input id="from" type="date" name="from" value="{{ request('from') }}">
            </div>
            <div class="form-field">
                <label for="to">To</label>
                <input id="to" type="date" name="to" value="{{ request('to') }}">
            </div>
            <button class="btn" type="submit">Filter</button>
        </form>

        <div class="settings-list">
            @foreach ($loginAttempts as $attempt)
                <div class="settings-list-item">
                    <p>{{ $attempt->occurred_at?->toDayDateTimeString() }}</p>
                    <p>{{ $attempt->success ? 'Success' : 'Failure' }}</p>
                    <p class="settings-help">Reason: {{ $attempt->failure_reason_code ?: 'n/a' }}</p>
                    <p class="settings-help">Device summary: unavailable (hashed)</p>
                    <p class="settings-help">Approx location: unavailable (hashed)</p>
                </div>
            @endforeach
        </div>
        <div class="pagination">{{ $loginAttempts->links() }}</div>
    </div>
@endsection
