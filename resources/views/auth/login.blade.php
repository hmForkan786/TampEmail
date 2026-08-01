@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
    <div class="card" style="max-width: 26rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Sign in</h1>

        @if (session('identityStatus'))
            <div class="alert alert--success">{{ session('identityStatus') }}</div>
        @endif

        <form method="POST" action="{{ route('login.store') }}">
            @csrf

            <div class="form-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            </div>

            <div class="form-field">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">
            </div>

            <div class="form-field">
                <label style="display:flex; align-items:center; gap:0.4rem;">
                    <input type="checkbox" name="remember" value="1" style="width:auto;"> Remember me
                </label>
            </div>

            <button type="submit" class="btn btn--primary">Sign in</button>
        </form>

        <p class="muted" style="margin-top:1rem;">
            <a href="{{ route('password.request') }}">Forgot password?</a>
            ·
            <a href="{{ route('register') }}">Create account</a>
            ·
            <a href="{{ route('account.recovery') }}">Account recovery</a>
        </p>
    </div>
@endsection
