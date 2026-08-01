<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\User;
use App\Services\Settings\NotificationPreferenceService;
use App\Services\Settings\SettingsAnalyticsRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsNotificationController extends SettingsController
{
    public function edit(
        Request $request,
        NotificationPreferenceService $notifications,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'notifications']);

        return $this->settingsView('notifications', [
            'preferences' => $notifications->listForUser($user),
            'marketingConsent' => (bool) optional($user->identityPreference)->marketing_consent,
            'marketingConsentAt' => $user->identityPreference?->marketing_consent_at,
            'policyVersion' => (string) config('settings.marketing.policy_version'),
        ]);
    }

    public function update(Request $request, NotificationPreferenceService $notifications): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.category' => ['required', 'string'],
            'preferences.*.channel' => ['required', 'string'],
            'preferences.*.enabled' => ['required', 'boolean'],
        ]);

        $notifications->updateMany($user, $validated['preferences']);

        return back()->with('settingsStatus', __('Notification preferences saved.'));
    }

    public function updateMarketing(Request $request, NotificationPreferenceService $notifications): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'marketing_consent' => ['required', 'boolean'],
        ]);

        $notifications->updateMarketingConsent($user, (bool) $validated['marketing_consent'], 'settings');

        return back()->with('settingsStatus', __('Marketing consent updated.'));
    }
}
