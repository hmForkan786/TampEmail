@extends('settings.layout')

@section('title', 'Security settings')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Security</h1>
        <p class="settings-help">
            Password policy: min {{ $passwordPolicy['min_length'] }} chars
            @if ($passwordPolicy['require_mixed_case']), mixed case @endif
            @if ($passwordPolicy['require_number']), number @endif
            @if ($passwordPolicy['require_symbol']), symbol @endif.
        </p>

        <h2>Current email</h2>
        <p>{{ $user->email }} — {{ $user->email_verified_at ? 'verified' : 'unverified' }}</p>
        @if (! $user->email_verified_at)
            <form method="POST" action="{{ route('settings.security.verification.resend') }}" class="settings-inline">
                @csrf
                <button class="btn" type="submit">Resend verification</button>
            </form>
        @endif

        @if ($user->pending_email)
            <p class="settings-help">Pending email change is awaiting verification.</p>
            <form method="POST" action="{{ route('settings.security.email.cancel') }}">
                @csrf
                <button class="btn" type="submit">Cancel pending email change</button>
            </form>
        @endif
    </div>

    <div class="settings-card">
        <h2>Change password</h2>
        <form method="POST" action="{{ route('settings.security.password') }}">
            @csrf
            <div class="form-field">
                <label for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
            </div>
            <div class="form-field">
                <label for="password">New password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password">
            </div>
            <div class="form-field">
                <label for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
            </div>
            <button class="btn btn--primary" type="submit">Update password</button>
        </form>
    </div>

    <div class="settings-card">
        <h2>Request email change</h2>
        <p class="settings-help">Uses staged verification. Sessions are revoked after the new address is confirmed.</p>
        <form method="POST" action="{{ route('settings.security.email') }}">
            @csrf
            <div class="form-field">
                <label for="email">New email</label>
                <input id="email" name="email" type="email" required autocomplete="email">
            </div>
            <button class="btn btn--primary" type="submit">Request email change</button>
        </form>
    </div>
@endsection
