@extends('settings.layout')

@section('title', 'Settings')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Settings overview</h1>
        <p class="settings-help">Safe account summary. Secrets, raw session IDs, and payment credentials are never shown here.</p>
    </div>

    <div class="settings-grid settings-grid--cards">
        <div class="settings-card">
            <h2>Profile</h2>
            <p>{{ $summary['profile_complete'] ? 'Complete' : 'Incomplete' }}</p>
            <p class="settings-help">Email verified: {{ $summary['email_verified'] ? 'yes' : 'no' }}</p>
        </div>
        <div class="settings-card">
            <h2>Account</h2>
            <p>Status: {{ $summary['account_status'] }}</p>
            <p class="settings-help">Pending email change: {{ $summary['pending_email'] ? 'yes' : 'no' }}</p>
        </div>
        <div class="settings-card">
            <h2>Sessions</h2>
            <p>Active: {{ $summary['active_sessions'] }}</p>
            <p class="settings-help">{{ $summary['session_enumeration_supported'] ? 'Session management available' : 'Session driver does not support listing' }}</p>
        </div>
        <div class="settings-card">
            <h2>API keys</h2>
            <p>Active: {{ $summary['active_api_keys'] }}</p>
        </div>
        <div class="settings-card">
            <h2>Plan &amp; usage</h2>
            <p>Plan: {{ $summary['current_plan'] ?? 'n/a' }}</p>
            <p class="settings-help">Subscription: {{ $summary['subscription_status'] ?? 'n/a' }}</p>
        </div>
        <div class="settings-card">
            <h2>Notifications</h2>
            <p>{{ $summary['notification_enabled_count'] }} / {{ $summary['notification_total_count'] }} enabled</p>
        </div>
        @if ($summary['affiliate'])
            <div class="settings-card">
                <h2>Affiliate</h2>
                <p>Status: {{ $summary['affiliate']['status'] }}</p>
                <p class="settings-help">Code: {{ $summary['affiliate']['affiliate_code'] }}</p>
            </div>
        @endif
        <div class="settings-card">
            <h2>Security recommendations</h2>
            @if ($summary['security_recommendations'] === [])
                <p class="settings-help">No urgent recommendations.</p>
            @else
                <ul>
                    @foreach ($summary['security_recommendations'] as $tip)
                        <li>{{ $tip }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
