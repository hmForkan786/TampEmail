@extends('layouts.app')
@section('title', 'Sender profiles')
@section('content')
<h1>Sender profiles</h1>

<form method="POST" action="{{ route('outbound-sender-profiles.store') }}" class="card" style="margin-bottom:1.5rem;">
    @csrf
    <h2 style="margin-top:0;font-size:1rem;">Create profile</h2>
    <div class="form-field">
        <label for="create_inbox_id">Inbox</label>
        <select id="create_inbox_id" name="inbox_id" required>
            @foreach ($inboxes as $inbox)
                <option value="{{ $inbox->id }}" @selected(old('inbox_id') == $inbox->id)>{{ $inbox->full_address }}</option>
            @endforeach
        </select>
    </div>
    <div class="form-field">
        <label for="create_name">Name</label>
        <input id="create_name" name="name" value="{{ old('name') }}" required maxlength="100">
    </div>
    <div class="form-field">
        <label for="create_display_name">Display name</label>
        <input id="create_display_name" name="display_name" value="{{ old('display_name') }}" maxlength="255">
    </div>
    <div class="form-field">
        <label for="create_reply_to_address">Reply-To address</label>
        <input id="create_reply_to_address" name="reply_to_address" value="{{ old('reply_to_address') }}" maxlength="320">
    </div>
    <div class="form-field">
        <label for="create_reply_to_name">Reply-To name</label>
        <input id="create_reply_to_name" name="reply_to_name" value="{{ old('reply_to_name') }}" maxlength="255">
    </div>
    <div class="form-field">
        <label for="create_signature_text">Text signature</label>
        <textarea id="create_signature_text" name="signature_text" rows="3">{{ old('signature_text') }}</textarea>
    </div>
    <div class="form-field">
        <label for="create_signature_html">HTML signature</label>
        <textarea id="create_signature_html" name="signature_html" rows="4">{{ old('signature_html') }}</textarea>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_send" value="1" @checked(old('include_on_send', true))> Include signature on send</label>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_reply" value="1" @checked(old('include_on_reply', true))> Include signature on reply</label>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_forward" value="1" @checked(old('include_on_forward', false))> Include signature on forward</label>
    </div>
    <button class="btn btn--primary">Create profile</button>
</form>

@if ($profiles->isEmpty())
    <p class="muted">No sender profiles yet.</p>
@else
    <div class="stack">
        @foreach ($profiles as $profile)
            <div class="card">
                <div class="row" style="justify-content:space-between;flex-wrap:wrap;">
                    <div>
                        <strong>{{ $profile->name }}</strong>
                        @if ($profile->is_default)
                            <span class="badge badge--sent">Default</span>
                        @endif
                        @if (! $profile->is_active)
                            <span class="badge badge--cancelled">Inactive</span>
                        @endif
                    </div>
                    <div class="row" style="flex-wrap:wrap;">
                        <a href="{{ route('outbound-sender-profiles.edit', $profile) }}" class="btn">Edit</a>
                        @if (! $profile->is_default)
                            <form method="POST" action="{{ route('outbound-sender-profiles.default', $profile) }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="version" value="{{ $profile->version }}">
                                <button class="btn">Make default</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('outbound-sender-profiles.destroy', $profile) }}" style="display:inline" onsubmit="return confirm('Delete this sender profile?')">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="version" value="{{ $profile->version }}">
                            <button class="btn btn--danger">Delete</button>
                        </form>
                    </div>
                </div>
                <p class="muted" style="margin:0.5rem 0 0;">{{ $profile->inbox->full_address ?? $profile->inbox_id }}</p>
                @if ($profile->display_name)
                    <p style="margin:0.25rem 0 0;">Display name: {{ $profile->display_name }}</p>
                @endif
                @if ($profile->reply_to_address)
                    <p style="margin:0.25rem 0 0;">Reply-To: {{ $profile->reply_to_address }}@if ($profile->reply_to_name) ({{ $profile->reply_to_name }})@endif</p>
                @endif
                @if ($profile->signature_text)
                    <p style="margin:0.5rem 0 0;font-size:0.875rem;"><span class="muted">Text signature:</span> {{ \Illuminate\Support\Str::limit($profile->signature_text, 120) }}</p>
                @endif
                @if ($profile->signature_html)
                    <div style="margin-top:0.5rem;">
                        <span class="muted" style="font-size:0.875rem;">HTML signature preview:</span>
                        <div class="html-body-frame" style="margin-top:0.25rem;max-height:8rem;font-size:0.875rem;">
                            {!! app(\App\Services\Inbound\InboundHtmlSanitizer::class)->sanitize($profile->signature_html) !!}
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection
