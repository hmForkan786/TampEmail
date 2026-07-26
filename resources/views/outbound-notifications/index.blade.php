@extends('layouts.app')
@section('title','Notifications')
@section('content')
<div class="row" style="justify-content:space-between"><h1>Notifications</h1><form method="post" action="{{ route('outbound-notifications.read-all') }}">@csrf<button class="btn">Mark all as read</button></form></div>
<p><a href="{{ route('outbound-notifications.index',['unread'=>1]) }}">Unread only</a> · <a href="{{ route('outbound-notification-preferences.index') }}">Notification preferences</a></p>
<div class="card"><table class="table"><thead><tr><th>Status</th><th>Event</th><th>Summary</th><th>When</th><th></th></tr></thead><tbody>@forelse($notifications as $notification)<tr><td>{{ $notification->read_at ? 'Read' : 'Unread' }}</td><td>{{ str_replace('outbound.','',str_replace('_',' ',$notification->event_type)) }}</td><td>{{ $notification->payload['summary'] ?? 'Outbound status updated.' }}</td><td>{{ $notification->created_at?->diffForHumans() }}</td><td>@if(!$notification->read_at)<form method="post" action="{{ route('outbound-notifications.read',$notification) }}">@csrf<button class="btn">Mark read</button></form>@endif</td></tr>@empty<tr><td colspan="5">No notifications.</td></tr>@endforelse</tbody></table></div>{{ $notifications->links() }}
@endsection
