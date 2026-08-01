@extends('layouts.app')

@section('title', 'Close account')

@section('content')
    <div class="card" style="max-width: 32rem;">
        <h1 style="margin-top:0;">Close account</h1>
        <p>Closing your account blocks future login, revokes sessions and API keys, and preserves billing/audit/affiliate financial records.</p>
        <p class="muted">Grace period: {{ $graceDays }} day(s). Self-service restore supported: {{ $restoreSupported ? 'yes' : 'no' }}.</p>

        <form method="POST" action="{{ route('account.close.store') }}">
            @csrf
            <div class="form-field">
                <label for="password">Confirm password</label>
                <input id="password" type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn--danger">Close my account</button>
        </form>
    </div>
@endsection
