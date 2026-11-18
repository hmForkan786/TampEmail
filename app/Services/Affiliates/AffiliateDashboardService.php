<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateConversionStatus;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;

/**
 * Builds the affiliate-facing dashboard summary. Never exposes raw referred
 * user data; only a masked email is included for recent conversions.
 */
final class AffiliateDashboardService
{
    public function __construct(private readonly AffiliateBalanceService $balances) {}

    /**
     * @return array<string, mixed>
     */
    public function forProfile(AffiliateProfile $profile): array
    {
        $clicks = AffiliateAttribution::query()->where('affiliate_profile_id', $profile->getKey())->count();
        $signups = AffiliateAttribution::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->whereNotNull('converted_user_id')
            ->count();

        $totalConversions = AffiliateConversion::query()->where('affiliate_profile_id', $profile->getKey())->count();
        $approvedConversions = AffiliateConversion::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->where('status', AffiliateConversionStatus::Approved->value)
            ->count();

        $clickToSignupRate = $clicks > 0 ? round(($signups / $clicks) * 100, 2) : 0.0;
        $signupToConversionRate = $signups > 0 ? round(($totalConversions / $signups) * 100, 2) : 0.0;

        $plan = $profile->plan;
        $currency = ($plan instanceof AffiliateCommissionPlan && $plan->currency !== null)
            ? $plan->currency
            : (string) config('affiliates.default_currency', 'USD');
        $balance = $this->balances->project($profile, $currency);

        $recentWithdrawals = AffiliateWithdrawal::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(static fn (AffiliateWithdrawal $withdrawal): array => [
                'id' => $withdrawal->getKey(),
                'amount_minor' => $withdrawal->amount_minor,
                'currency' => $withdrawal->currency,
                'status' => $withdrawal->status->value,
                'requested_at' => $withdrawal->requested_at->toIso8601String(),
                'paid_at' => $withdrawal->paid_at?->toIso8601String(),
            ])
            ->all();

        $recentConversions = AffiliateConversion::query()
            ->where('affiliate_profile_id', $profile->getKey())
            ->orderByDesc('qualified_at')
            ->limit(10)
            ->with('referredUser')
            ->get()
            ->map(fn (AffiliateConversion $conversion): array => [
                'id' => $conversion->getKey(),
                'status' => $conversion->status->value,
                'order_amount_minor' => $conversion->order_amount_minor,
                'commission_amount_minor' => $conversion->commission_amount_minor,
                'currency' => $conversion->currency,
                'qualified_at' => $conversion->qualified_at->toIso8601String(),
                'referred_user_email' => $this->maskEmail($conversion->referredUser?->email),
            ])
            ->all();

        return [
            'affiliate_code' => $profile->affiliate_code,
            'status' => $profile->status->value,
            'referral_url' => url('/?ref='.$profile->affiliate_code),
            'clicks' => $clicks,
            'signups' => $signups,
            'conversions' => $totalConversions,
            'approved_conversions' => $approvedConversions,
            'click_to_signup_rate' => $clickToSignupRate,
            'signup_to_conversion_rate' => $signupToConversionRate,
            'currency' => $currency,
            'balance' => $balance,
            'recent_withdrawals' => $recentWithdrawals,
            'recent_conversions' => $recentConversions,
        ];
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
