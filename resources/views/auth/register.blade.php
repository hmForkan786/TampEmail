@extends('layouts.app')

@section('title', 'Create account')

@section('content')
    <div class="card" style="max-width: 28rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Create account</h1>
        <p class="muted">Mode: {{ $mode->label() }}</p>

        <form method="POST" action="{{ route('register.store') }}">
            @csrf
            <input type="hidden" name="_form_started_at" value="{{ $formStartedAt }}">

            <div class="form-field" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                <label for="{{ $honeypotField }}">Website</label>
                <input id="{{ $honeypotField }}" type="text" name="{{ $honeypotField }}" tabindex="-1" autocomplete="off">
            </div>

            <div class="form-field">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
            </div>

            <div class="form-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            </div>

            <div class="form-field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>

            <div class="form-field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            @if ($mode->value === 'invite_only')
                <div class="form-field">
                    <label for="invite_token">Invite token</label>
                    <input id="invite_token" type="text" name="invite_token" value="{{ old('invite_token') }}" required autocomplete="off">
                </div>
            @endif

            <div class="form-field">
                <label style="display:flex; align-items:center; gap:0.4rem;">
                    <input type="checkbox" name="terms_accepted" value="1" style="width:auto;" @checked(old('terms_accepted')) required>
                    I accept the terms of service
                </label>
            </div>

            <div class="form-field">
                <label style="display:flex; align-items:center; gap:0.4rem;">
                    <input type="checkbox" name="marketing_consent" value="1" style="width:auto;" @checked(old('marketing_consent'))>
                    Send me product updates (optional)
                </label>
            </div>

            <button type="submit" class="btn btn--primary">Register</button>
        </form>

        <p class="muted" style="margin-top:1rem;">
            Already have an account? <a href="{{ route('login') }}">Sign in</a>
        </p>
    </div>
@endsection
