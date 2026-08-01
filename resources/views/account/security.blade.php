@extends('layouts.app')

@section('title', 'Account security')

@section('content')
    <div class="card" style="max-width: 36rem;">
        <h1 style="margin-top:0;">Account security</h1>
        <ul class="stack">
            <li><a href="{{ route('account.sessions') }}">Manage sessions</a></li>
            <li><a href="{{ route('password.request') }}">Reset password</a></li>
            <li><a href="{{ route('account.close') }}">Close account</a></li>
        </ul>
    </div>
@endsection
