@extends('layouts.app')

@section('title', 'Verify email')

@section('content')
    <div class="card" style="max-width: 28rem; margin: 3rem auto;">
        <h1 style="margin-top:0;">Verify your email</h1>
        <p>Before accessing product features, please verify your email address.</p>

        @if (session('identityStatus'))
            <div class="alert alert--success">{{ session('identityStatus') }}</div>
        @endif

        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button type="submit" class="btn btn--primary">Resend verification email</button>
        </form>
    </div>
@endsection
