<?php

declare(strict_types=1);

namespace App\Services\Affiliates;

use App\Enums\AffiliateAttributionStatus;
use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateConversionStatus;
use App\Enums\AffiliateFraudDecision;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Models\AffiliateAttribution;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateCommissionPlan;
use App\Models\AffiliateConversion;
use App\Models\AffiliateFraudFlag;
use App\Models\AffiliateProfile;
use App\Models\BillingOrder;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid billing order into an affiliate conversion + commission
 * ledger entry. Idempotent on `billing_order_id`; safe to call repeatedly
 * (e.g. via retried queue jobs) without ever double-crediting an affiliate.
 */
final class AffiliateConversionService
{
    public function __construct(
        private readonly AffiliateCommissionCalculator $calculator,
        private readonly AffiliateFraudEvaluationService $fraudEvaluator,
        private readonly AuditLogWriter $audit,
        private readonly AffiliateNotificationService $notifications,
    ) {}

    public function recordFromPaidOrder(string $billingOrderId): ?AffiliateConversion
    {
        if (config('affiliates.enabled') !== true) {
            return null;
        }

        $order = BillingOrder::query()->find($billingOrderId);

        if (! $order instanceof BillingOrder) {
            return null;
        }

        $existing = AffiliateConversion::query()->where('billing_order_id', $order->getKey())->first();

        if ($existing instanceof AffiliateConversion) {
            return $existing;
        }

        if ($order->status !== BillingOrderStatus::Paid) {
            return null;
        }

        $mappedType = $this->mapOrderType($order);

        if ($mappedType === null || config('affiliates.eligible_order_types.'.$mappedType) !== true) {
            return null;
        }

        if ($order->total_minor <= 0) {
            return null;
        }

        $currency = strtoupper($order->currency);

        if (! in_array($currency, config('affiliates.supported_currencies', []), true)) {
            return null;
        }

        $attribution = $this->resolveAttribution($order);

        if (! $attribution instanceof AffiliateAttribution) {
            return null;
        }

        $affiliate = $attribution->profile;

        if (! $affiliate instanceof AffiliateProfile || ! $affiliate->isActive()) {
            return null;
        }

        $buyer = $order->user;

        if (! $buyer instanceof User) {
            return null;
        }

        if ($affiliate->user_id === $buyer->getKey()) {
            return null;
        }

        $plan = $affiliate->plan;

        if (! $plan instanceof AffiliateCommissionPlan || ! $this->isPlanActiveAt($plan, now())) {
            return null;
        }

        if ($plan->new_customer_only && $this->hasPriorPurchase($buyer, $order)) {
            return null;
        }

        $decision = $this->fraudEvaluator->evaluate($affiliate, $buyer, $attribution, $order);

        $commissionAmountMinor = 0;

        if ($decision !== AffiliateFraudDecision::Reject) {
            $commissionAmountMinor = $this->calculator->calculate($order, $plan);

            if ($commissionAmountMinor <= 0) {
                return null;
            }
        }

        $reasonCode = $decision === AffiliateFraudDecision::Allow ? null : $this->resolveReasonCode($affiliate, $buyer, $decision);

        return DB::transaction(function () use (
            $order, $attribution, $affiliate, $buyer, $plan, $decision, $reasonCode, $commissionAmountMinor,
        ): ?AffiliateConversion {
            $lockedOrder = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $lockedOrder instanceof BillingOrder) {
                return null;
            }

            $existing = AffiliateConversion::query()->where('billing_order_id', $lockedOrder->getKey())->lockForUpdate()->first();

            if ($existing instanceof AffiliateConversion) {
                return $existing;
            }

            $status = match ($decision) {
                AffiliateFraudDecision::Reject => AffiliateConversionStatus::Rejected,
                AffiliateFraudDecision::ManualReview => AffiliateConversionStatus::Pending,
                AffiliateFraudDecision::Allow => AffiliateConversionStatus::Approved,
            };

            $currency = strtoupper($lockedOrder->currency);

            $conversion = AffiliateConversion::query()->create([
                'affiliate_profile_id' => $affiliate->getKey(),
                'attribution_id' => $attribution->getKey(),
                'referred_user_id' => $buyer->getKey(),
                'billing_order_id' => $lockedOrder->getKey(),
                'subscription_id' => $lockedOrder->subscription_id,
                'invoice_id' => $lockedOrder->invoice?->getKey(),
                'status' => $status,
                'order_amount_minor' => $lockedOrder->total_minor,
                'currency' => $currency,
                'commission_amount_minor' => $status === AffiliateConversionStatus::Rejected ? 0 : $commissionAmountMinor,
                'commission_plan_snapshot' => $this->calculator->snapshotPlan($plan),
                'qualified_at' => now(),
                'approved_at' => $status === AffiliateConversionStatus::Approved ? now() : null,
                'rejected_at' => $status === AffiliateConversionStatus::Rejected ? now() : null,
                'reason_code' => $reasonCode,
            ]);

            if ($status !== AffiliateConversionStatus::Rejected) {
                AffiliateCommissionEntry::query()->create([
                    'affiliate_profile_id' => $affiliate->getKey(),
                    'conversion_id' => $conversion->getKey(),
                    'entry_type' => AffiliateCommissionEntryType::Commission,
                    'amount_minor' => $commissionAmountMinor,
                    'currency' => $currency,
                    'status' => AffiliateCommissionEntryStatus::Pending,
                    'available_at' => now()->addDays($plan->commission_hold_days ?? (int) config('affiliates.commission_hold_days', 14)),
                    'reference_type' => AffiliateConversion::class,
                    'reference_id' => $conversion->getKey(),
                    'idempotency_key' => 'commission:'.$conversion->getKey(),
                ]);
            }

            if ($attribution->status !== AffiliateAttributionStatus::Converted) {
                $attribution->forceFill([
                    'converted_user_id' => $buyer->getKey(),
                    'converted_at' => $attribution->converted_at ?? now(),
                    'status' => AffiliateAttributionStatus::Converted,
                ])->save();
            }

            $this->audit->write('affiliate.conversion_created', $buyer->getKey(), $conversion, null, [
                'affiliate_profile_id' => $affiliate->getKey(),
                'status' => $status->value,
                'fraud_decision' => $decision->value,
            ]);

            if ($status !== AffiliateConversionStatus::Rejected) {
                $this->audit->write('affiliate.commission_created', $buyer->getKey(), $conversion, null, [
                    'amount_minor' => $commissionAmountMinor,
                    'currency' => $currency,
                ]);

                if ($affiliate->user instanceof User) {
                    $this->notifications->notify($affiliate->user, 'affiliate.commission_earned', [
                        'amount_minor' => $commissionAmountMinor,
                        'currency' => $currency,
                    ], 'commission-earned:'.$conversion->getKey());
                }
            }

            Cache::increment(config('affiliates.metrics.cache_key_prefix').'conversions_created');

            return $conversion;
        });
    }

    private function mapOrderType(BillingOrder $order): ?string
    {
        $metadata = $order->metadata ?? [];

        if (($metadata['recovery'] ?? false) === true) {
            return 'recovery';
        }

        return match ($order->type) {
            BillingOrderType::Purchase => 'initial_purchase',
            BillingOrderType::Renewal => 'renewal',
            BillingOrderType::Upgrade => 'upgrade',
            default => null,
        };
    }

    private function resolveAttribution(BillingOrder $order): ?AffiliateAttribution
    {
        $metadata = $order->metadata ?? [];
        $attributionId = $metadata['affiliate_attribution_id'] ?? null;

        if (is_string($attributionId) && $attributionId !== '') {
            $attribution = AffiliateAttribution::query()->find($attributionId);

            if ($attribution instanceof AffiliateAttribution
                && ! in_array($attribution->status, [
                    AffiliateAttributionStatus::Expired,
                    AffiliateAttributionStatus::Invalidated,
                ], true)
            ) {
                return $attribution;
            }
        }

        return AffiliateAttribution::query()
            ->where('converted_user_id', $order->user_id)
            ->where('status', AffiliateAttributionStatus::Converted->value)
            ->orderByDesc('converted_at')
            ->first();
    }

    private function hasPriorPurchase(User $buyer, BillingOrder $order): bool
    {
        $priorOrder = BillingOrder::query()
            ->where('user_id', $buyer->getKey())
            ->where('id', '!=', $order->getKey())
            ->where('status', BillingOrderStatus::Paid->value)
            ->exists();

        if ($priorOrder) {
            return true;
        }

        return AffiliateConversion::query()
            ->where('referred_user_id', $buyer->getKey())
            ->where('billing_order_id', '!=', $order->getKey())
            ->whereIn('status', [AffiliateConversionStatus::Pending->value, AffiliateConversionStatus::Approved->value])
            ->exists();
    }

    private function isPlanActiveAt(AffiliateCommissionPlan $plan, Carbon $at): bool
    {
        if (! $plan->isActive()) {
            return false;
        }

        if ($plan->starts_at !== null && $plan->starts_at->isAfter($at)) {
            return false;
        }

        if ($plan->ends_at !== null && $plan->ends_at->isBefore($at)) {
            return false;
        }

        return true;
    }

    private function resolveReasonCode(AffiliateProfile $affiliate, User $buyer, AffiliateFraudDecision $decision): string
    {
        $flag = AffiliateFraudFlag::query()
            ->where('affiliate_profile_id', $affiliate->getKey())
            ->where('referred_user_id', $buyer->getKey())
            ->where('decision', $decision->value)
            ->orderByDesc('created_at')
            ->first();

        if ($flag instanceof AffiliateFraudFlag && $flag->reason_codes !== []) {
            return implode(',', $flag->reason_codes);
        }

        return $decision->value;
    }
}
