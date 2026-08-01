@extends('layouts.app')

@section('title', 'Reset password')

@section('content')
    <div class="card" style="max-width: 26rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Reset password</h1>

        <form method="POST" action="{{ route('password.store') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">

            <div class="form-field">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email', $email) }}" required autofocus>
            </div>

            <div class="form-field">
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
            </div>

            <div class="form-field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
            </div>

            <button type="submit" class="btn btn--primary">Reset password</button>
        </form>
    </div>
@endsection
