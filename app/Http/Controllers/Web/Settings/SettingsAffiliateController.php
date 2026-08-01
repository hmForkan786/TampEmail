<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Enums\AffiliatePayoutMethod;
use App\Models\User;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class SettingsAffiliateController extends SettingsController
{
    public function edit(
        Request $request,
        UserSettingsSummaryService $summary,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'affiliate']);

        return $this->settingsView('affiliate', [
            'affiliate' => $summary->affiliateSummary($user),
            'payoutMethods' => array_map(
                static fn (AffiliatePayoutMethod $method): string => $method->value,
                AffiliatePayoutMethod::cases(),
            ),
        ]);
    }

    public function updatePayout(Request $request, UserSettingsSummaryService $summary): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'payout_method' => ['required', 'string', 'in:'.implode(',', array_map(
                static fn (AffiliatePayoutMethod $method): string => $method->value,
                AffiliatePayoutMethod::cases(),
            ))],
            'payout_details' => ['required', 'string', 'max:2000'],
        ]);

        $summary->updateAffiliatePayout(
            $user,
            (string) $validated['password'],
            (string) $validated['payout_method'],
            (string) $validated['payout_details'],
        );

        return back()->with('settingsStatus', __('Affiliate payout details updated.'));
    }
}
