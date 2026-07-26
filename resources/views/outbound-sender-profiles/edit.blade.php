@extends('layouts.app')
@section('title', 'Edit sender profile')
@section('content')
<h1>Edit sender profile</h1>
<p class="muted"><a href="{{ route('outbound-sender-profiles.index') }}">&larr; Back to sender profiles</a></p>

<form method="POST" action="{{ route('outbound-sender-profiles.update', $profile) }}" class="card">
    @csrf
    @method('PATCH')
    <input type="hidden" name="version" value="{{ $profile->version }}">
    <div class="form-field">
        <label>Inbox</label>
        <input value="{{ $profile->inbox->full_address ?? $profile->inbox_id }}" disabled>
    </div>
    <div class="form-field">
        <label for="edit_name">Name</label>
        <input id="edit_name" name="name" value="{{ old('name', $profile->name) }}" required maxlength="100">
    </div>
    <div class="form-field">
        <label for="edit_display_name">Display name</label>
        <input id="edit_display_name" name="display_name" value="{{ old('display_name', $profile->display_name) }}" maxlength="255">
    </div>
    <div class="form-field">
        <label for="edit_reply_to_address">Reply-To address</label>
        <input id="edit_reply_to_address" name="reply_to_address" value="{{ old('reply_to_address', $profile->reply_to_address) }}" maxlength="320">
    </div>
    <div class="form-field">
        <label for="edit_reply_to_name">Reply-To name</label>
        <input id="edit_reply_to_name" name="reply_to_name" value="{{ old('reply_to_name', $profile->reply_to_name) }}" maxlength="255">
    </div>
    <div class="form-field">
        <label for="edit_signature_text">Text signature</label>
        <textarea id="edit_signature_text" name="signature_text" rows="3">{{ old('signature_text', $profile->signature_text) }}</textarea>
    </div>
    <div class="form-field">
        <label for="edit_signature_html">HTML signature</label>
        <textarea id="edit_signature_html" name="signature_html" rows="4">{{ old('signature_html', $profile->signature_html) }}</textarea>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_send" value="1" @checked(old('include_on_send', $profile->include_on_send))> Include signature on send</label>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_reply" value="1" @checked(old('include_on_reply', $profile->include_on_reply))> Include signature on reply</label>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="include_on_forward" value="1" @checked(old('include_on_forward', $profile->include_on_forward))> Include signature on forward</label>
    </div>
    <div class="form-field">
        <label><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $profile->is_active))> Active</label>
    </div>
    <button class="btn btn--primary">Save changes</button>
</form>
@endsection
