<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\DTOs\Billing\StartCheckoutData;
use App\Enums\BillingCycle;
use App\Models\AffiliateProfile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Settings\AffiliatePayoutUpdatedNotification;
use App\Services\Affiliates\AffiliateDashboardService;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\Invoice\BillingHistoryService;
use App\Services\Billing\Invoice\InvoicePdfService;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Commercial\CommercialUsageSummaryService;
use App\Services\Entitlement\EntitlementService;
use App\Services\Identity\AccountClosureService;
use App\Services\Identity\SessionManagementService;
use App\Services\Subscription\SubscriptionLifecycleService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Aggregated settings dashboard + billing/account control surface.
 */
final class UserSettingsSummaryService
{
    public function __construct(
        private readonly UserProfileSettingsService $profile,
        private readonly NotificationPreferenceService $notifications,
        private readonly SessionManagementService $sessions,
        private readonly CommercialUsageSummaryService $usage,
        private readonly EntitlementService $entitlements,
        private readonly SettingsApiKeyService $apiKeys,
        private readonly BillingPreferenceService $billingPreferences,
        private readonly BillingHistoryService $billingHistory,
        private readonly CheckoutService $checkout,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly SubscriptionLifecycleService $subscriptions,
        private readonly InvoicePdfService $invoicePdf,
        private readonly AccountClosureService $closure,
        private readonly AffiliateDashboardService $affiliateDashboard,
        private readonly PrivacyPreferenceService $privacy,
        private readonly AuditLogWriter $audit,
        private readonly SettingsAnalyticsRecorder $analytics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user, ?string $currentSessionId = null): array
    {
        $this->analytics->record('settings.section_viewed', (string) $user->getKey(), dimensions: [
            'section' => 'dashboard',
        ]);

        $usage = $this->usage->forUser($user, evaluateThresholds: false);
        $plan = $this->entitlements->effectivePlan($user);
        $subscription = $this->entitlements->currentSubscription($user);
        $sessions = $this->sessions->listForUser($user, $currentSessionId);
        $keys = $this->apiKeys->listForUser($user);
        $activeKeys = array_values(array_filter($keys, static fn (array $key): bool => ($key['active'] ?? false) === true));
        $prefs = $this->notifications->listForUser($user);
        $enabledPrefs = count(array_filter($prefs, static fn (array $row): bool => $row['enabled'] === true));

        $affiliate = null;
        $profile = AffiliateProfile::query()->where('user_id', $user->getKey())->first();
        if ($profile instanceof AffiliateProfile) {
            $affiliate = [
                'status' => $profile->status->value,
                'affiliate_code' => $profile->affiliate_code,
            ];
        }

        return [
            'profile_complete' => $this->profile->isProfileComplete($user),
            'email_verified' => $user->email_verified_at !== null,
            'account_status' => $user->status->value,
            'pending_email' => $user->pending_email !== null,
            'active_sessions' => count($sessions),
            'session_enumeration_supported' => $this->sessions->supportsEnumeration(),
            'active_api_keys' => count($activeKeys),
            'current_plan' => $plan !== null ? $plan->slug : ($usage['plan'] ?? null),
            'subscription_status' => $usage['subscription_status'] ?? null,
            'usage' => $usage,
            'notification_enabled_count' => $enabledPrefs,
            'notification_total_count' => count($prefs),
            'affiliate' => $affiliate,
            'security_recommendations' => $this->securityRecommendations($user, $sessions, $activeKeys),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billingSummary(User $user): array
    {
        $usage = $this->usage->forUser($user, evaluateThresholds: false);
        $subscription = $this->entitlements->currentSubscription($user);
        $plan = $this->entitlements->effectivePlan($user);

        return [
            'plan' => $plan !== null ? $plan->slug : null,
            'plan_name' => $plan !== null ? $plan->name : null,
            'subscription' => $subscription ? [
                'status' => $subscription->status->value,
                'starts_at' => $subscription->starts_at->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
                'auto_renew' => (bool) $subscription->auto_renew,
            ] : null,
            'usage' => $usage,
            'preferences' => $this->billingPreferences->summary($user),
            'gateways' => array_values(array_intersect(
                $this->gateways->registeredProviders(),
                (array) config('billing.enabled_gateways', []),
            )),
            'orders' => $this->billingHistory->orders((string) $user->getKey(), [], 10)->items(),
            'payments' => $this->billingHistory->payments((string) $user->getKey(), [], 10)->items(),
            'invoices' => $this->billingHistory->invoices((string) $user->getKey(), [], 10)->items(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function startCheckout(User $user, array $input): array
    {
        $result = $this->checkout->startCheckout(new StartCheckoutData(
            userId: (string) $user->getKey(),
            planId: (string) $input['plan_id'],
            gateway: (string) $input['gateway'],
            billingCycle: BillingCycle::from((string) ($input['billing_cycle'] ?? 'monthly')),
            idempotencyKey: (string) $input['idempotency_key'],
            successUrl: (string) $input['success_url'],
            cancelUrl: (string) $input['cancel_url'],
            returnUrl: isset($input['return_url']) ? (string) $input['return_url'] : null,
            clientReference: isset($input['client_reference']) ? (string) $input['client_reference'] : null,
            metadata: ['source' => 'settings'],
        ));

        $this->analytics->record('settings.billing_action_started', (string) $user->getKey(), dimensions: [
            'action' => 'checkout',
        ]);

        return $result;
    }

    public function cancelAtPeriodEnd(User $user): Subscription
    {
        $subscription = $this->requireOwnedSubscription($user);
        $result = $this->subscriptions->cancelAtPeriodEnd($subscription, (string) $user->getKey(), 'settings');
        $this->analytics->record('settings.billing_action_started', (string) $user->getKey(), dimensions: [
            'action' => 'cancel_at_period_end',
        ]);

        return $result;
    }

    public function downloadInvoice(User $user, string $invoiceId): Response
    {
        $invoice = $this->billingHistory->ownedInvoice($invoiceId, (string) $user->getKey());
        $pdf = $this->invoicePdf->render($invoice, (string) $user->getKey());

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$invoice->invoice_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function closeAccount(User $user, string $password, string $confirmationPhrase, ?string $reason = null): User
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        $expected = (string) config('settings.account_closure.confirmation_phrase', 'DELETE MY ACCOUNT');
        if (! hash_equals($expected, trim($confirmationPhrase))) {
            throw ValidationException::withMessages([
                'confirmation_phrase' => __('Type the confirmation phrase exactly to close your account.'),
            ]);
        }

        $closed = $this->closure->requestClosure($user, true);

        $this->audit->write('settings.account_closure_requested', (string) $user->getKey(), $closed, metadata: [
            'reason_provided' => is_string($reason) && $reason !== '',
        ]);
        $this->analytics->record('settings.account_closure_started', (string) $user->getKey());

        return $closed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function affiliateSummary(User $user): ?array
    {
        $profile = AffiliateProfile::query()->where('user_id', $user->getKey())->first();
        if (! $profile instanceof AffiliateProfile) {
            return null;
        }

        $dashboard = $this->affiliateDashboard->forProfile($profile);
        $details = $profile->payout_details_encrypted;

        return array_merge($dashboard, [
            'payout_method' => $profile->payout_method?->value,
            'payout_details_masked' => is_string($details) && $details !== ''
                ? str_repeat('*', max(4, mb_strlen($details) - 4)).mb_substr($details, -4)
                : null,
            'promotion_channel' => $profile->promotion_channel,
        ]);
    }

    public function updateAffiliatePayout(User $user, string $password, string $method, string $details): AffiliateProfile
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('Please confirm your password.'),
            ]);
        }

        $profile = AffiliateProfile::query()->where('user_id', $user->getKey())->firstOrFail();
        $profile->forceFill([
            'payout_method' => $method,
            'payout_details_encrypted' => $details,
        ])->save();

        $this->audit->write('settings.affiliate_payout_updated', (string) $user->getKey(), $profile, metadata: [
            'payout_method' => $method,
        ]);
        $user->notify(new AffiliatePayoutUpdatedNotification);

        return $profile->fresh() ?? $profile;
    }

    public function privacy(): PrivacyPreferenceService
    {
        return $this->privacy;
    }

    private function requireOwnedSubscription(User $user): Subscription
    {
        $subscription = $this->entitlements->currentSubscription($user);
        if (! $subscription instanceof Subscription) {
            throw ValidationException::withMessages([
                'subscription' => __('No active subscription is available for this action.'),
            ]);
        }

        return $subscription;
    }

    /**
     * @param  list<array<string, mixed>>  $sessions
     * @param  list<array<string, mixed>>  $activeKeys
     * @return list<string>
     */
    private function securityRecommendations(User $user, array $sessions, array $activeKeys): array
    {
        $tips = [];

        if ($user->email_verified_at === null) {
            $tips[] = 'Verify your email address.';
        }
        if ($user->pending_email !== null) {
            $tips[] = 'Complete or cancel your pending email change.';
        }
        if (count($sessions) > 3) {
            $tips[] = 'Review and revoke unused sessions.';
        }
        if ($activeKeys === []) {
            $tips[] = 'Create an API key only when you need programmatic access.';
        }

        return $tips;
    }
}
