<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Http\Requests\Settings\ChangePasswordSettingsRequest;
use App\Http\Requests\Settings\RequestEmailChangeSettingsRequest;
use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserSecuritySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsSecurityController extends SettingsController
{
    public function edit(
        Request $request,
        UserSecuritySettingsService $security,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'security']);

        return $this->settingsView('security', [
            'user' => $user,
            'passwordPolicy' => $security->passwordPolicySummary(),
        ]);
    }

    public function updatePassword(ChangePasswordSettingsRequest $request, UserSecuritySettingsService $security): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $security->changePassword(
            $user,
            (string) $request->input('current_password'),
            (string) $request->input('password'),
            $request->session()->getId(),
        );

        return back()->with('settingsStatus', __('Password changed.'));
    }

    public function requestEmailChange(RequestEmailChangeSettingsRequest $request, UserSecuritySettingsService $security): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $security->requestEmailChange($user, (string) $request->input('email'));

        return back()->with('settingsStatus', __('Check the new address to confirm the email change.'));
    }

    public function cancelEmailChange(Request $request, UserSecuritySettingsService $security): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $security->cancelEmailChange($user);

        return back()->with('settingsStatus', __('Pending email change cancelled.'));
    }

    public function resendVerification(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return back()->with('settingsStatus', __('Email already verified.'));
        }

        $user->sendEmailVerificationNotification();

        return back()->with('settingsStatus', __('Verification link sent if eligible.'));
    }
}
