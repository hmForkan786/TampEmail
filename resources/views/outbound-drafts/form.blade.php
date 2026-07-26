@extends('layouts.app')
@section('title',$draft ? 'Edit draft' : 'Compose draft')
@section('content')
<h1>{{ $draft ? 'Edit draft' : 'Compose draft' }}</h1>
@if(session('outboundStatus'))<div class="alert alert--success">{{ session('outboundStatus') }}</div>@endif
@if(session('outboundError'))<div class="alert alert--error">{{ session('outboundError') }}</div>@endif
<form method="POST" action="{{ $draft ? route('outbound-drafts.update',$draft) : route('outbound-drafts.store') }}" class="card">@csrf @if($draft) @method('PATCH')<input type="hidden" name="version" value="{{ $draft->draft_version }}">@endif
<div class="form-field"><label>Sender inbox</label><select name="inbox_id" required>@foreach($inboxes as $inbox)<option value="{{ $inbox->id }}" @selected(old('inbox_id',$draft?->inbox_id)==$inbox->id)>{{ $inbox->full_address }}</option>@endforeach</select></div>
<div class="form-field"><label>Sender profile</label><select name="sender_profile_id"><option value="">— none —</option>@foreach(($profiles ?? collect()) as $profile)<option value="{{ $profile->id }}" @selected(old('sender_profile_id',$draft?->sender_profile_id)==$profile->id)>{{ $profile->name }} ({{ $profile->inbox->full_address ?? $profile->inbox_id }})</option>@endforeach</select></div>
<div class="form-field"><label>Operation</label><select name="operation"><option value="send" @selected($operation==='send')>Send</option><option value="reply" @selected($operation==='reply')>Reply</option><option value="forward" @selected($operation==='forward')>Forward</option></select></div>
<div class="form-field"><label>To (comma-separated)</label><input name="to[]" value="{{ old('to.0',$draft?->to_recipients[0] ?? '') }}"></div><div class="form-field"><label>Subject</label><input name="subject" value="{{ old('subject',$draft?->subject) }}"></div><div class="form-field"><label>Text body</label><textarea name="text_body" rows="8">{{ old('text_body',$draft?->text_body) }}</textarea></div><div class="form-field"><label>HTML body</label><textarea name="html_body" rows="5">{{ old('html_body',$draft?->html_body) }}</textarea></div><button class="btn btn--primary">Save draft</button></form>
@if($draft)
<form method="POST" action="{{ route('outbound-drafts.submit',$draft) }}" style="margin-top:1rem">@csrf<input type="hidden" name="version" value="{{ $draft->draft_version }}"><button class="btn btn--primary">Submit now</button></form>
<form method="POST" action="{{ route('outbound-drafts.schedule',$draft) }}" class="card" style="margin-top:1rem">@csrf
<h2 style="margin-top:0;font-size:1rem;">Schedule for later</h2>
<input type="hidden" name="version" value="{{ $draft->draft_version }}">
<div class="form-field"><label for="local_date">Date</label><input id="local_date" type="date" name="local_date" value="{{ old('local_date') }}" required></div>
<div class="form-field"><label for="local_time">Time</label><input id="local_time" type="time" name="local_time" value="{{ old('local_time') }}" required></div>
<div class="form-field"><label for="timezone">Timezone</label><select id="timezone" name="timezone" required>@foreach($timezones as $tz)<option value="{{ $tz }}" @selected(old('timezone','UTC')===$tz)>{{ $tz }}</option>@endforeach</select></div>
<button class="btn btn--primary">Schedule</button>
</form>
<form method="POST" action="{{ route('outbound-drafts.destroy',$draft) }}" style="margin-top:1rem" onsubmit="return confirm('Delete this draft?')">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $draft->draft_version }}"><button class="btn btn--danger">Delete</button></form>
@endif
@endsection
