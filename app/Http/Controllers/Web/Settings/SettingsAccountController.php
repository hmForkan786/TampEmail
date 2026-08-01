<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsAccountController extends SettingsController
{
    public function edit(Request $request, SettingsAnalyticsRecorder $analytics): View
    {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'account']);

        return $this->settingsView('account', [
            'graceDays' => (int) config('identity.closure.grace_days', 7),
            'restoreSupported' => (bool) config('identity.closure.restore_supported', false),
            'confirmationPhrase' => (string) config('settings.account_closure.confirmation_phrase', 'DELETE MY ACCOUNT'),
        ]);
    }

    public function destroy(Request $request, UserSettingsSummaryService $summary): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'confirmation_phrase' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $summary->closeAccount(
            $user,
            (string) $validated['password'],
            (string) $validated['confirmation_phrase'],
            $validated['reason'] ?? null,
        );

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('identityStatus', __('Your account has been closed.'));
    }
}
