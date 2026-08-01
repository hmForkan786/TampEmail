@extends('settings.layout')

@section('title', 'Account')

@section('settings')
    <div class="settings-card settings-danger">
        <h1 style="margin-top:0;">Close account</h1>
        <p>This reuses the Identity closure service. Consequences:</p>
        <ul>
            <li>Login blocked</li>
            <li>Sessions revoked</li>
            <li>API keys revoked</li>
            <li>Inbox operations stop</li>
            <li>Financial/audit/affiliate financial records retained</li>
            <li>Restore {{ $restoreSupported ? 'may be supported by policy' : 'is not supported' }}</li>
            <li>Cooling / grace period: {{ $graceDays }} day(s)</li>
        </ul>

        <form method="POST" action="{{ route('settings.account.close') }}">
            @csrf
            <div class="form-field">
                <label for="password">Current password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password">
            </div>
            <div class="form-field">
                <label for="confirmation_phrase">Type “{{ $confirmationPhrase }}”</label>
                <input id="confirmation_phrase" name="confirmation_phrase" type="text" required autocomplete="off">
            </div>
            <div class="form-field">
                <label for="reason">Reason (optional)</label>
                <textarea id="reason" name="reason" rows="3" maxlength="500"></textarea>
            </div>
            <button class="btn btn--danger" type="submit">Close my account</button>
        </form>
    </div>
@endsection
