@extends('layouts.app')
@section('title','Notification preferences')
@section('content')
<h1>Notification preferences</h1><p class="muted">Preferences are managed through the API. Routine updates are in-app; failures and allowance alerts may also be emailed.</p>
<div class="card"><p>Global notifications: {{ $preference->notifications_enabled ? 'enabled' : 'disabled' }}</p><p>In-app: {{ $preference->in_app_enabled ? 'enabled' : 'disabled' }} · Email: {{ $preference->email_enabled ? 'enabled' : 'disabled' }}</p><ul>@foreach($events as $event)<li>{{ $event }} — in-app {{ (($preference->events[$event]['in_app'] ?? false) ? 'enabled' : 'disabled') }}, email {{ (($preference->events[$event]['email'] ?? false) ? 'enabled' : 'disabled') }}</li>@endforeach</ul></div>
@endsection
