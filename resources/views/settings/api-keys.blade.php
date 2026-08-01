@extends('settings.layout')

@section('title', 'API keys')

@section('settings')
    <div class="settings-card">
        <h1 style="margin-top:0;">API keys</h1>
        <p class="settings-help">Secrets are shown exactly once after create/rotate. Commercial max_api_keys quota is enforced by the existing API-key lifecycle.</p>

        @if ($plainToken)
            <div class="settings-secret" role="status">
                <div>Copy now — this secret will not be shown again:</div>
                <code id="api-key-secret">{{ $plainToken }}</code>
            </div>
        @endif
    </div>

    <div class="settings-card">
        <h2>Create key</h2>
        <form method="POST" action="{{ route('settings.api-keys.store') }}">
            @csrf
            <div class="form-field">
                <label for="name">Name</label>
                <input id="name" name="name" type="text" required maxlength="120">
            </div>
            <div class="form-field">
                <label for="scopes">Scopes</label>
                <select id="scopes" name="scopes[]" multiple size="6">
                    @foreach ($scopes as $scope)
                        <option value="{{ $scope }}">{{ $scope }}</option>
                    @endforeach
                </select>
            </div>
            @if ($requirePassword)
                <div class="form-field">
                    <label for="password_create">Password</label>
                    <input id="password_create" name="password" type="password" required>
                </div>
            @endif
            <button class="btn btn--primary" type="submit">Create API key</button>
        </form>
    </div>

    <div class="settings-list">
        @forelse ($keys as $key)
            <div class="settings-list-item">
                <p><strong>{{ $key['name'] }}</strong> ({{ $key['prefix'] }}…)</p>
                <p class="settings-help">Scopes: {{ implode(', ', $key['scopes'] ?? []) }}</p>
                <p class="settings-help">Created: {{ $key['created_at'] ?? 'n/a' }}</p>
                <p class="settings-help">Last used: {{ $key['last_used_at'] ?? 'never' }}</p>
                <p class="settings-help">Expires: {{ $key['expires_at'] ?? 'none' }}</p>
                <p class="settings-help">Status: {{ $key['active'] ? 'active' : 'inactive' }}</p>

                @if ($key['active'])
                    <form method="POST" action="{{ route('settings.api-keys.rotate', $key['id']) }}" class="settings-inline">
                        @csrf
                        <div class="form-field">
                            <label for="password-rotate-{{ $key['id'] }}">Password</label>
                            <input id="password-rotate-{{ $key['id'] }}" name="password" type="password" required>
                        </div>
                        <button class="btn" type="submit">Rotate</button>
                    </form>
                    <form method="POST" action="{{ route('settings.api-keys.destroy', $key['id']) }}" class="settings-inline">
                        @csrf
                        @method('DELETE')
                        <div class="form-field">
                            <label for="password-revoke-{{ $key['id'] }}">Password</label>
                            <input id="password-revoke-{{ $key['id'] }}" name="password" type="password" required>
                        </div>
                        <button class="btn btn--danger" type="submit">Revoke</button>
                    </form>
                @endif
            </div>
        @empty
            <div class="settings-card"><p class="settings-help">No API keys yet.</p></div>
        @endforelse
    </div>
@endsection
