<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Models\User;
use App\Services\Entitlement\EntitlementService;

/** Normalized owner-visible commercial usage and remaining quota summary. */
final class CommercialUsageSummaryService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly CommercialQuotaResolver $quota,
        private readonly CommercialThresholdNotificationService $thresholds,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user, bool $evaluateThresholds = true): array
    {
        $plan = $this->entitlements->effectivePlan($user);
        $subscription = $this->entitlements->currentSubscription($user);
        $features = [];

        foreach ((array) config('commercial.summary_features', []) as $featureKey => $kind) {
            if (! is_string($featureKey) || ! is_string($kind)) {
                continue;
            }

            $snapshot = $this->quota->snapshot($user, $featureKey, $kind);
            if ($snapshot === null) {
                continue;
            }

            if ($snapshot['unlimited']) {
                $features[$featureKey] = [
                    'limit' => null,
                    'used' => $snapshot['used'],
                    'remaining' => null,
                    'unlimited' => true,
                    'reset_at' => $snapshot['reset_at'],
                ];

                continue;
            }

            $features[$featureKey] = [
                'limit' => $snapshot['limit'],
                'used' => $snapshot['used'],
                'remaining' => $snapshot['remaining'],
                'unlimited' => false,
                'reset_at' => $snapshot['reset_at'],
            ];

            if ($evaluateThresholds && $snapshot['limit'] > 0) {
                $this->thresholds->evaluate(
                    $user,
                    $featureKey,
                    $snapshot['used'],
                    $snapshot['limit'],
                    $snapshot['reset_at'] ?? 'inventory',
                );
            }
        }

        $upgrade = $this->upgradeMetadata($user, $features);

        return [
            'plan' => $plan?->slug,
            'subscription_status' => $subscription !== null ? $subscription->status->value : 'free',
            'upgrade_required' => $upgrade['upgrade_required'],
            'recommended_plan' => $upgrade['recommended_plan'],
            'features' => $features,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $features
     * @return array{upgrade_required: bool, recommended_plan: string|null}
     */
    private function upgradeMetadata(User $user, array $features): array
    {
        $plan = $this->entitlements->effectivePlan($user);
        $recommended = (string) config('commercial.recommended_plan_slug', 'premium');
        $onRecommended = $plan !== null && $plan->slug === $recommended;

        foreach ($features as $snapshot) {
            if (($snapshot['unlimited'] ?? false) === true) {
                continue;
            }

            $remaining = $snapshot['remaining'] ?? null;
            if ($remaining === 0) {
                return [
                    'upgrade_required' => ! $onRecommended,
                    'recommended_plan' => $onRecommended ? null : $recommended,
                ];
            }
        }

        return [
            'upgrade_required' => false,
            'recommended_plan' => null,
        ];
    }
}
