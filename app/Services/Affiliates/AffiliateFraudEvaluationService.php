<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateFraudDecision;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateFraudFlag;
use App\Models\AffiliateProfile;
use App\Models\BillingOrder;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;

/**
 * Deterministic, rule-based fraud screening for affiliate conversions.
 *
 * Every non-allow decision is persisted as an {@see AffiliateFraudFlag} with
 * safe (non-PII) reason codes so it can be audited and manually reviewed.
 */
final class AffiliateFraudEvaluationService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    public function evaluate(
        AffiliateProfile $affiliate,
        User $buyer,
        ?AffiliateAttribution $attribution,
        BillingOrder $order,
    ): AffiliateFraudDecision {
        $reasonCodes = [];

        if ($affiliate->user_id === $buyer->getKey()) {
            $reasonCodes[] = 'self_referral';
        }

        $affiliateEmail = $this->normalizeEmail($affiliate->user !== null ? $affiliate->user->email : null);
        $buyerEmail = $this->normalizeEmail($buyer->email);

        if ($affiliateEmail !== null && $affiliateEmail === $buyerEmail) {
            $reasonCodes[] = 'self_referral_email';
        }

        $metadata = $order->metadata ?? [];
        $orderIpHash = is_string($metadata['ip_hash'] ?? null) ? $metadata['ip_hash'] : null;

        if ($attribution !== null && $attribution->ip_hash !== null && $orderIpHash !== null
            && hash_equals($attribution->ip_hash, $orderIpHash)
        ) {
            $reasonCodes[] = 'same_ip_hash';
        }

        $fastConversionSeconds = (int) config('affiliates.fraud.fast_conversion_seconds', 300);

        if ($fastConversionSeconds > 0
            && $attribution !== null
            && now()->diffInSeconds($attribution->first_seen_at, true) <= $fastConversionSeconds
        ) {
            $reasonCodes[] = 'fast_conversion';
        }

        if (! $affiliate->isActive()) {
            $reasonCodes[] = 'affiliate_suspended';
        }

        if (($metadata['affiliate_cookie_tampered'] ?? false) === true) {
            $reasonCodes[] = 'cookie_tampered';
        }

        $decision = $this->decide($reasonCodes);

        if ($decision !== AffiliateFraudDecision::Allow) {
            $flag = AffiliateFraudFlag::query()->create([
                'affiliate_profile_id' => $affiliate->getKey(),
                'conversion_id' => null,
                'attribution_id' => $attribution?->getKey(),
                'referred_user_id' => $buyer->getKey(),
                'decision' => $decision,
                'reason_codes' => $reasonCodes,
                'context' => ['billing_order_id' => $order->getKey()],
            ]);

            $this->audit->write('affiliate.fraud_flagged', $buyer->getKey(), $flag, null, [
                'affiliate_profile_id' => $affiliate->getKey(),
                'decision' => $decision->value,
                'reason_codes' => $reasonCodes,
            ]);
        }

        return $decision;
    }

    /**
     * @param  list<string>  $reasonCodes
     */
    private function decide(array $reasonCodes): AffiliateFraudDecision
    {
        if (in_array('self_referral', $reasonCodes, true) || in_array('self_referral_email', $reasonCodes, true)) {
            return AffiliateFraudDecision::Reject;
        }

        if ($reasonCodes !== []) {
            return AffiliateFraudDecision::ManualReview;
        }

        return AffiliateFraudDecision::Allow;
    }

    private function normalizeEmail(?string $email): ?string
    {
        $trimmed = trim((string) $email);

        return $trimmed === '' ? null : strtolower($trimmed);
    }
}
