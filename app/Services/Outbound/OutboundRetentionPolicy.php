<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Models\User;
use App\Services\Entitlement\EntitlementService;

/**
 * Resolves the outbound content-retention window (in days) for a user.
 *
 * Resolution order:
 * 1. Plan entitlement `outbound_retention_days` via
 *    {@see EntitlementService::featureValue()}, expected shape
 *    `['days' => N]`.
 * 2. Plan free/premium default from config/outbound_retention.php.
 *
 * A resolved value of 0 means the category is disabled (fail closed) for
 * that user: content must never be redacted for them by this policy.
 */
final class OutboundRetentionPolicy
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function contentRetentionDays(User $user): int
    {
        $entitled = $this->entitlements->featureValue(
            $user,
            (string) config('outbound_retention.feature_key', 'outbound_retention_days'),
        );

        if (is_array($entitled) && array_key_exists('days', $entitled) && is_numeric($entitled['days'])) {
            return $this->validated((int) $entitled['days']);
        }

        $plan = $this->entitlements->currentPlan($user);
        $configKey = $plan !== null && $plan->isPaid() ? 'outbound_retention.premium_days' : 'outbound_retention.free_days';

        return $this->validated((int) config($configKey, 0));
    }

    /**
     * Fail-closed bound check mirroring config/outbound_retention.php:
     * an invalid or out-of-range value disables the category (0).
     */
    private function validated(int $days): int
    {
        return $days >= 1 && $days <= 3650 ? $days : 0;
    }
}
