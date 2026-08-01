@extends('layouts.app')

@section('title', 'Forgot password')

@section('content')
    <div class="card" style="max-width: 26rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Forgot password</h1>
        <p class="muted">If an account exists for that email, a reset link will be sent when eligible.</p>

        @if (session('identityStatus'))
            <div class="alert alert--success">{{ session('identityStatus') }}</div>
        @endif

        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="form-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
            </div>
            <button type="submit" class="btn btn--primary">Send reset link</button>
        </form>
    </div>
@endsection
