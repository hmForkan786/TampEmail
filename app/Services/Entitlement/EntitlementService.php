<?php

declare(strict_types=1);

namespace App\Services\Entitlement;

use App\Enums\SubscriptionStatus;
use App\Enums\ValueType;
use App\Models\Feature;
use App\Models\Pivots\FeaturePlan;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Feature\FeatureService;

/** Resolves commercial entitlements fail-closed without lifecycle caching. */
final class EntitlementService
{
    public const FREE_PLAN_SLUG = 'free';

    public function __construct(private readonly FeatureService $featureService) {}

    /** Returns the current lifecycle subscription, including grace for limited-policy resolution. */
    public function currentSubscription(User $user): ?Subscription
    {
        $now = now();

        return Subscription::query()
            ->with('plan')
            ->where('user_id', $user->getKey())
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trial->value,
                SubscriptionStatus::RenewalDue->value,
                SubscriptionStatus::Grace->value,
            ])
            ->where('starts_at', '<=', $now)
            ->where(fn ($query) => $query
                ->where(fn ($access) => $access
                    ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::RenewalDue->value])
                    ->where(fn ($boundary) => $boundary->whereNull('ends_at')->orWhere('ends_at', '>', $now)))
                ->orWhere(fn ($trial) => $trial
                    ->where('status', SubscriptionStatus::Trial->value)
                    ->where(fn ($boundary) => $boundary->whereNull('trial_ends_at')->orWhere('trial_ends_at', '>', $now)))
                ->orWhere('status', SubscriptionStatus::Grace->value))
            ->whereHas('plan', fn ($query) => $query->where('is_active', true))
            ->orderByRaw('case when status = ? then 0 when status = ? then 1 when status = ? then 2 else 3 end', [SubscriptionStatus::Active->value, SubscriptionStatus::RenewalDue->value, SubscriptionStatus::Trial->value])
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Resolves the paid/trial plan or the active canonical Free plan. */
    public function effectivePlan(User $user): ?Plan
    {
        $subscription = $this->currentSubscription($user);
        if ($subscription !== null && $subscription->status !== SubscriptionStatus::Grace) {
            return $subscription->plan;
        }

        return Plan::query()->where('slug', self::FREE_PLAN_SLUG)->where('is_active', true)->first();
    }

    /** Backwards-compatible name for the effective plan resolver. */
    public function currentPlan(User $user): ?Plan
    {
        return $this->effectivePlan($user);
    }

    /** Boolean entitlement parser. Missing, malformed, numeric, and null values deny. */
    public function allows(User $user, string $featureKey): bool
    {
        $feature = $this->getFeature($user, $featureKey);
        if ($feature === null || $feature->value_type !== ValueType::Boolean) {
            return false;
        }

        $value = $this->featureValue($user, $featureKey);
        $raw = $value['enabled'] ?? null;

        return $raw === true || $raw === 1 || $raw === '1';
    }

    /** Alias retained for existing callers. */
    public function hasFeature(User $user, string $featureKey): bool
    {
        return $this->allows($user, $featureKey);
    }

    /** Numeric entitlement parser. Missing, null, invalid, and negative values resolve to zero. */
    public function limit(User $user, string $featureKey): int
    {
        $feature = $this->getFeature($user, $featureKey);
        if ($feature === null || ! in_array($feature->value_type, [ValueType::Integer, ValueType::Json], true)) {
            return 0;
        }

        $value = $this->featureValue($user, $featureKey);
        $raw = $value['limit'] ?? null;

        if (is_int($raw)) {
            return max(0, $raw);
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return 0;
    }

    /** @return array<string, mixed>|null Raw mapped value for legacy structured callers. */
    public function featureValue(User $user, string $featureKey): ?array
    {
        $feature = $this->getFeature($user, $featureKey);
        $plan = $this->effectivePlan($user);
        if ($feature === null || $plan === null) {
            return null;
        }

        $mapping = FeaturePlan::query()
            ->where('feature_id', $feature->getKey())
            ->where('plan_id', $plan->getKey())
            ->first();

        return $mapping?->feature_value;
    }

    /** Returns only an active feature explicitly mapped to the effective plan. */
    public function getFeature(User $user, string $featureKey): ?Feature
    {
        $feature = $this->featureService->findByKey($featureKey);
        $plan = $this->effectivePlan($user);

        if ($feature === null || ! $feature->isActive() || $plan === null || ! $plan->is_active) {
            return null;
        }

        $mapped = FeaturePlan::query()
            ->where('feature_id', $feature->getKey())
            ->where('plan_id', $plan->getKey())
            ->exists();

        return $mapped ? $feature : null;
    }
}
