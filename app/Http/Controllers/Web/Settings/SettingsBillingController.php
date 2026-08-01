<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Settings;

use App\Models\User;
use App\Services\Settings\BillingPreferenceService;
use App\Services\Settings\SettingsAnalyticsRecorder;
use App\Services\Settings\UserSettingsSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Throwable;

final class SettingsBillingController extends SettingsController
{
    public function edit(
        Request $request,
        UserSettingsSummaryService $summary,
        SettingsAnalyticsRecorder $analytics,
    ): View {
        /** @var User $user */
        $user = $request->user();
        $analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: ['section' => 'billing']);

        return $this->settingsView('billing', [
            'billing' => $summary->billingSummary($user),
        ]);
    }

    public function updatePreferences(Request $request, BillingPreferenceService $preferences): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'billing_email' => ['nullable', 'email', 'max:255'],
            'invoice_name' => ['nullable', 'string', 'max:255'],
            'invoice_address' => ['nullable', 'string', 'max:2000'],
            'invoice_locale' => ['nullable', 'string', 'max:16'],
            'tax_identifier' => ['nullable', 'string', 'max:64'],
        ]);

        $preferences->update($user, $validated);

        return back()->with('settingsStatus', __('Billing preferences saved. Existing invoices were not changed.'));
    }

    public function checkout(Request $request, UserSettingsSummaryService $summary): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'plan_id' => ['required', 'string'],
            'gateway' => ['required', 'string'],
            'billing_cycle' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $summary->startCheckout($user, [
                ...$validated,
                'success_url' => route('settings.billing'),
                'cancel_url' => route('settings.billing'),
            ]);
        } catch (Throwable $exception) {
            return back()->withErrors(['checkout' => $exception->getMessage()]);
        }

        $redirect = $result['session']->checkout_url ?? null;

        if (is_string($redirect) && $redirect !== '') {
            return redirect()->away($redirect);
        }

        return back()->with('settingsStatus', __('Checkout session created.'));
    }

    public function cancelAtPeriodEnd(Request $request, UserSettingsSummaryService $summary): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $summary->cancelAtPeriodEnd($user);
        } catch (Throwable $exception) {
            return back()->withErrors(['subscription' => $exception->getMessage()]);
        }

        return back()->with('settingsStatus', __('Subscription will cancel at period end.'));
    }

    public function downloadInvoice(Request $request, string $invoice, UserSettingsSummaryService $summary): Response
    {
        /** @var User $user */
        $user = $request->user();

        return $summary->downloadInvoice($user, $invoice);
    }
}
