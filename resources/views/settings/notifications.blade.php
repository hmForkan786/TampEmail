@extends('settings.layout')

@section('title', 'Notification preferences')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Notifications</h1>
        <p class="settings-help">Critical security notifications cannot be disabled. Transactional billing email remains enforced. Marketing consent is separate.</p>

        <form method="POST" action="{{ route('settings.notifications.update') }}">
            @csrf
            @method('PUT')
            <div class="settings-list">
                @foreach ($preferences as $index => $pref)
                    <div class="settings-list-item">
                        <input type="hidden" name="preferences[{{ $index }}][category]" value="{{ $pref['category'] }}">
                        <input type="hidden" name="preferences[{{ $index }}][channel]" value="{{ $pref['channel'] }}">
                        <label>
                            <strong>{{ $pref['category'] }}</strong> via {{ $pref['channel'] }}
                            @if ($pref['critical']) <span class="badge">critical</span> @endif
                        </label>
                        <select name="preferences[{{ $index }}][enabled]" @disabled($pref['critical'])>
                            <option value="1" @selected($pref['enabled'])>Enabled</option>
                            <option value="0" @selected(! $pref['enabled'])>Disabled</option>
                        </select>
                        @if ($pref['critical'])
                            <input type="hidden" name="preferences[{{ $index }}][enabled]" value="1">
                        @endif
                    </div>
                @endforeach
            </div>
            <button class="btn btn--primary" type="submit" style="margin-top:1rem;">Save preferences</button>
        </form>
    </div>

    <div class="settings-card">
        <h2>Marketing consent</h2>
        <p class="settings-help">Policy version: {{ $policyVersion }}. Opt-in is never implied by registration or purchase in this settings flow.</p>
        <p>Current: {{ $marketingConsent ? 'opted in' : 'opted out' }}
            @if ($marketingConsentAt) ({{ $marketingConsentAt }}) @endif
        </p>
        <form method="POST" action="{{ route('settings.notifications.marketing') }}" class="settings-inline">
            @csrf
            <input type="hidden" name="marketing_consent" value="1">
            <button class="btn" type="submit">Opt in</button>
        </form>
        <form method="POST" action="{{ route('settings.notifications.marketing') }}" class="settings-inline">
            @csrf
            <input type="hidden" name="marketing_consent" value="0">
            <button class="btn" type="submit">Opt out</button>
        </form>
    </div>
@endsection
