@extends('settings.layout')

@section('title', 'Profile settings')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">Profile</h1>
        <p class="settings-help">Email is not editable here. Use Security to request a verified email change.</p>

        <form method="POST" action="{{ route('settings.profile.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <input type="hidden" name="updated_at" value="{{ optional($user->updated_at)->toIso8601String() }}">

            <div class="form-field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="120" autocomplete="name">
            </div>

            <div class="form-field">
                <label for="locale">Locale</label>
                <select id="locale" name="locale" required>
                    @foreach ($locales as $locale)
                        <option value="{{ $locale }}" @selected(old('locale', $user->locale) === $locale)>{{ $locale }}</option>
                    @endforeach
                </select>
            </div>

            <div class="form-field">
                <label for="timezone">Timezone</label>
                <select id="timezone" name="timezone" required>
                    @foreach ($timezones as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timezone', $user->timezone) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
            </div>

            @if ($avatarEnabled)
                <div class="form-field">
                    <label for="avatar">Avatar (private storage)</label>
                    <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp">
                </div>
            @endif

            <div class="form-field">
                <label for="email_readonly">Email</label>
                <input id="email_readonly" type="email" value="{{ $user->email }}" disabled aria-disabled="true">
            </div>

            <button class="btn btn--primary" type="submit">Save profile</button>
        </form>
    </div>
@endsection
