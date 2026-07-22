<?php

declare(strict_types=1);

namespace App\Services\Entitlement;

use App\Enums\SubscriptionStatus;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Feature\FeatureService;

/**
 * Resolve feature entitlements for a user through their current subscription plan.
 *
 * Eligible subscription statuses are Active and Trial, with Active preferred.
 * All lookups are side-effect free and return soft misses instead of throwing.
 */
final class EntitlementService
{
    /**
     * @param FeatureService $featureService Feature catalog lookup service.
     */
    public function __construct(
        private readonly FeatureService $featureService,
    ) {}

    /**
     * Resolve the user's current entitlement-granting subscription.
     *
     * Selection strategy: eligible statuses are Active and Trial; Active is
     * preferred over Trial, then latest starts_at, then latest created_at,
     * then highest id. Cancelled and Expired subscriptions are ignored.
     *
     * @param User $user The user to resolve the subscription for.
     *
     * @return Subscription|null The current subscription, if any.
     */
    public function currentSubscription(User $user): ?Subscription
    {
        $eligible = Subscription::query()
            ->where('user_id', $user->getKey())
            ->whereIn('status', [
                SubscriptionStatus::Active,
                SubscriptionStatus::Trial,
            ])
            ->get();

        $sorted = $eligible->sort(function (Subscription $a, Subscription $b): int {
            $rank = fn (Subscription $subscription): int => $subscription->status === SubscriptionStatus::Active ? 0 : 1;

            if ($rank($a) !== $rank($b)) {
                return $rank($a) <=> $rank($b);
            }

            if ($a->starts_at != $b->starts_at) {
                return $b->starts_at <=> $a->starts_at;
            }

            if ($a->created_at != $b->created_at) {
                return $b->created_at <=> $a->created_at;
            }

            return $b->id <=> $a->id;
        });

        return $sorted->first();
    }

    /**
     * Resolve the plan attached to the user's current subscription.
     *
     * @param User $user The user to resolve the plan for.
     *
     * @return Plan|null The current plan, if any.
     */
    public function currentPlan(User $user): ?Plan
    {
        return $this->currentSubscription($user)?->plan;
    }

    /**
     * Determine whether the user's current plan includes the given feature.
     *
     * @param User   $user       The user to check.
     * @param string $featureKey Stable machine-readable feature identifier.
     *
     * @return bool Whether the feature is entitled.
     */
    public function hasFeature(User $user, string $featureKey): bool
    {
        return $this->getFeature($user, $featureKey) !== null;
    }

    /**
     * Resolve the entitled value for the given feature key.
     *
     * Resolution order: pivot feature_value, then the feature's catalog
     * default_value, then null.
     *
     * @param User   $user       The user to resolve the value for.
     * @param string $featureKey Stable machine-readable feature identifier.
     *
     * @return array<string, mixed>|null The resolved value payload, if any.
     */
    public function featureValue(User $user, string $featureKey): ?array
    {
        $feature = $this->getFeature($user, $featureKey);

        if ($feature === null) {
            return null;
        }

        /** @var \App\Models\Pivots\FeaturePlan|null $pivot */
        $pivot = $feature->pivot;

        if ($pivot !== null && $pivot->feature_value !== null) {
            return $pivot->feature_value;
        }

        return $feature->default_value;
    }

    /**
     * Resolve the entitled feature (with pivot data) for the given key.
     *
     * Only active catalog features attached to the user's current plan are
     * returned; all other cases resolve to null.
     *
     * @param User   $user       The user to resolve the feature for.
     * @param string $featureKey Stable machine-readable feature identifier.
     *
     * @return Feature|null The attached feature with pivot data, if entitled.
     */
    public function getFeature(User $user, string $featureKey): ?Feature
    {
        $feature = $this->featureService->findByKey($featureKey);

        if ($feature === null || ! $feature->isActive()) {
            return null;
        }

        $plan = $this->currentPlan($user);

        if ($plan === null) {
            return null;
        }

        return $plan->features()
            ->whereKey($feature->getKey())
            ->where('features.is_active', true)
            ->first();
    }
}
