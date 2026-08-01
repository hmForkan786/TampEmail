<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Enums\ApiKeyScope;
use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\SettingsApiKeyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsApiKeyController extends SettingsController
{
    public function index(
        Request $request,
        SettingsApiKeyService $apiKeys,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'api-keys']);

        return $this->settingsView('api-keys', [
            'keys' => $apiKeys->listForUser($user),
            'scopes' => array_map(static fn (ApiKeyScope $scope): string => $scope->value, ApiKeyScope::cases()),
            'plainToken' => $request->session()->pull('settings.api_key_plain_token'),
            'requirePassword' => (bool) config('settings.api_keys.require_password', true),
        ]);
    }

    public function store(Request $request, SettingsApiKeyService $apiKeys): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scopes' => ['nullable', 'array'],
            'scopes.*' => ['string'],
            'password' => [config('settings.api_keys.require_password', true) ? 'required' : 'nullable', 'string'],
        ]);

        $result = $apiKeys->create(
            $user,
            (string) $validated['name'],
            $validated['scopes'] ?? null,
            (string) ($validated['password'] ?? ''),
        );

        return redirect()
            ->route('settings.api-keys')
            ->with('settingsStatus', __('API key created. Copy the secret now — it will not be shown again.'))
            ->with('settings.api_key_plain_token', $result->plainToken);
    }

    public function rotate(Request $request, string $apiKey, SettingsApiKeyService $apiKeys): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate(['password' => ['required', 'string']]);
        $key = $apiKeys->ownedKey($user, $apiKey);
        $result = $apiKeys->rotate($user, $key, (string) $validated['password']);

        return redirect()
            ->route('settings.api-keys')
            ->with('settingsStatus', __('API key rotated. Copy the new secret now.'))
            ->with('settings.api_key_plain_token', $result->plainToken);
    }

    public function destroy(Request $request, string $apiKey, SettingsApiKeyService $apiKeys): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate(['password' => ['required', 'string']]);
        $key = $apiKeys->ownedKey($user, $apiKey);
        $apiKeys->revoke($user, $key, (string) $validated['password']);

        return back()->with('settingsStatus', __('API key revoked.'));
    }
}
