<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\User;
use App\Models\UserPrivacyExport;
use App\Services\Settings\PrivacyPreferenceService;
use App\Services\Settings\SettingsAnalyticsRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SettingsPrivacyController extends SettingsController
{
    public function edit(
        Request $request,
        PrivacyPreferenceService $privacy,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'privacy']);

        return $this->settingsView('privacy', [
            'center' => $privacy->centerSummary($user),
        ]);
    }

    public function requestExport(Request $request, PrivacyPreferenceService $privacy): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $request->validate(['password' => ['required', 'string']]);
        $ok = Hash::check((string) $request->input('password'), $user->password);
        $privacy->requestExport($user, $ok);

        return back()->with('settingsStatus', __('Export requested. You will be notified when it is ready.'));
    }

    public function downloadExport(Request $request, string $export, PrivacyPreferenceService $privacy): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = UserPrivacyExport::query()->whereKey($export)->firstOrFail();

        return $privacy->download($user, $model);
    }
}
