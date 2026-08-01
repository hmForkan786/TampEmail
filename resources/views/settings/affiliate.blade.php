@extends('settings.layout')

@section('title', 'Affiliate settings')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Affiliate</h1>
        @unless ($affiliate)
            <p class="settings-help">No affiliate profile is linked to this account.</p>
        @else
            <p>Status: {{ $affiliate['status'] }}</p>
            <p>Referral code: {{ $affiliate['affiliate_code'] }}</p>
            <p>Referral URL: {{ $affiliate['referral_url'] }}</p>
            <p>Payout method: {{ $affiliate['payout_method'] ?? 'n/a' }}</p>
            <p>Payout details: {{ $affiliate['payout_details_masked'] ?? 'not set' }}</p>
            <p class="settings-help">Commission/withdrawal summaries come from existing affiliate services. Existing withdrawal snapshots are never modified here.</p>
        @endunless
    </div>

    @if ($affiliate)
        <div class="settings-card">
            <h2>Update payout details</h2>
            <form method="POST" action="{{ route('settings.affiliate.payout') }}">
                @csrf
                @method('PUT')
                <div class="form-field">
                    <label for="payout_method">Payout method</label>
                    <select id="payout_method" name="payout_method" required>
                        @foreach ($payoutMethods as $method)
                            <option value="{{ $method }}" @selected(($affiliate['payout_method'] ?? null) === $method)>{{ $method }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-field">
                    <label for="payout_details">Payout details</label>
                    <textarea id="payout_details" name="payout_details" rows="3" required maxlength="2000"></textarea>
                </div>
                <div class="form-field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" required>
                </div>
                <button class="btn btn--primary" type="submit">Save payout details</button>
            </form>
        </div>
    @endif
@endsection
