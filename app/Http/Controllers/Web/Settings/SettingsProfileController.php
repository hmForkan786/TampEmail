<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Requests\Settings\UpdateProfileSettingsRequest;
use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserProfileSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsProfileController extends SettingsController
{
    public function edit(Request $request, SettingsAnalyticsRecorder $analytics): View
    {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'profile']);

        return $this->settingsView('profile', [
            'user' => $user,
            'locales' => (array) config('settings.locales', ['en']),
            'timezones' => (array) config('settings.timezones', ['UTC']),
            'avatarEnabled' => (bool) config('settings.avatar.enabled', false),
        ]);
    }

    public function update(UpdateProfileSettingsRequest $request, UserProfileSettingsService $profiles): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profiles->update(
            $user,
            $request->safe()->only(['name', 'locale', 'timezone', 'updated_at']),
            $request->file('avatar'),
        );

        return back()->with('settingsStatus', __('Profile updated.'));
    }
}
