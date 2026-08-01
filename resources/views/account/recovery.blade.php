@extends('layouts.app')

@section('title', 'Account recovery')

@section('content')
    <div class="card" style="max-width: 32rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Account recovery</h1>
        <p class="muted">Admin-assisted recovery for lost email access or suspected compromise. Do not upload identity documents here.</p>

        @if (session('identityStatus'))
            <div class="alert alert--success">{{ session('identityStatus') }}</div>
        @endif

        <form method="POST" action="{{ route('account.recovery.store') }}">
            @csrf
            <div class="form-field">
                <label for="claimed_email">Account email</label>
                <input id="claimed_email" type="email" name="claimed_email" value="{{ old('claimed_email') }}" required>
            </div>
            <div class="form-field">
                <label for="reason_code">Reason</label>
                <select id="reason_code" name="reason_code" required>
                    @foreach ($reasons as $value => $label)
                        <option value="{{ $value }}" @selected(old('reason_code') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-field">
                <label for="new_email">Requested new email (optional)</label>
                <input id="new_email" type="email" name="new_email" value="{{ old('new_email') }}">
            </div>
            <div class="form-field">
                <label for="evidence_notes">Notes (optional)</label>
                <textarea id="evidence_notes" name="evidence_notes" rows="4">{{ old('evidence_notes') }}</textarea>
            </div>
            <button type="submit" class="btn btn--primary">Submit recovery request</button>
        </form>
    </div>
@endsection
