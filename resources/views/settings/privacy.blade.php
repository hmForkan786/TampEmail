@extends('settings.layout')

@section('title', 'Privacy')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Privacy center</h1>
        <p class="settings-help">{{ $center['compliance_claim'] }}</p>
        <p>Privacy policy: <a href="{{ $center['privacy_policy_url'] }}">{{ $center['privacy_policy_version'] }}</a></p>
        <p>Marketing consent: {{ $center['marketing_consent'] ? 'opted in' : 'opted out' }}</p>
        <p>Login history retention: {{ $center['login_history_retention_days'] }} days</p>
        <p>Cookie preferences documented: {{ $center['cookie_preferences_documented'] ? 'yes' : 'no' }}</p>
        <p><a href="{{ route('settings.account') }}">Account closure</a></p>
    </div>

    <div class="settings-card">
        <h2>Data collected (summary)</h2>
        <ul>
            @foreach ($center['data_collected_summary'] as $key => $enabled)
                <li>{{ $key }}: {{ $enabled ? 'yes' : 'no' }}</li>
            @endforeach
        </ul>
        <p class="settings-help">Included export datasets: {{ implode(', ', $center['included_datasets']) }}</p>
        <p class="settings-help">Deferred datasets: {{ implode(', ', $center['deferred_datasets']) }}</p>
    </div>

    <div class="settings-card">
        <h2>Personal data export</h2>
        @if ($center['latest_export'])
            <p>Latest status: {{ $center['latest_export']['status'] }}</p>
            <p class="settings-help">Requested: {{ $center['latest_export']['requested_at'] }}</p>
            @if ($center['latest_export']['downloadable'])
                <a class="btn btn--primary" href="{{ route('settings.privacy.export.download', $center['latest_export']['id']) }}">Download export</a>
            @endif
        @endif

        @if ($center['export_enabled'])
            <form method="POST" action="{{ route('settings.privacy.export') }}" class="settings-inline" style="margin-top:1rem;">
                @csrf
                <div class="form-field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <button class="btn" type="submit">Request export</button>
            </form>
        @else
            <p class="settings-help">Export is currently disabled.</p>
        @endif
    </div>
@endsection
