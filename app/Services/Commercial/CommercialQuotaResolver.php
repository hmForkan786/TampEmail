<?php

declare(strict_types=1);

namespace App\Services\Commercial;

use App\Enums\ValueType;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Repositories\Contracts\InboxRepositoryInterface;
use App\Services\Entitlement\EntitlementService;
use App\Services\Webhook\WebhookEndpointService;

/** Central remaining-quota calculations for commercial finite features. */
final class CommercialQuotaResolver
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly InboxRepositoryInterface $inboxes,
        private readonly WebhookEndpointService $webhooks,
    ) {}

    /**
     * @return array{limit: int|null, used: int, remaining: int|null, unlimited: bool, reset_at: string|null}|null
     */
    public function snapshot(User $user, string $featureKey, string $kind): ?array
    {
        $limit = $this->resolveLimit($user, $featureKey);
        if ($limit === null) {
            return null;
        }

        if ($limit === PHP_INT_MAX) {
            return [
                'limit' => null,
                'used' => $this->resolveUsed($user, $featureKey, $kind),
                'remaining' => null,
                'unlimited' => true,
                'reset_at' => null,
            ];
        }

        $used = $this->resolveUsed($user, $featureKey, $kind);
        $periodEnd = $kind === 'period' ? $this->periodResetAt($user, $featureKey) : null;

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $this->remaining($limit, $used),
            'unlimited' => false,
            'reset_at' => $periodEnd,
        ];
    }

    /** Fail-closed finite limit, or null when unmapped; PHP_INT_MAX means unlimited. */
    public function resolveLimit(User $user, string $featureKey): ?int
    {
        $feature = $this->entitlements->getFeature($user, $featureKey);
        if ($feature === null) {
            return null;
        }

        if ($feature->value_type === ValueType::Boolean) {
            return null;
        }

        $value = $this->entitlements->featureValue($user, $featureKey);
        if ($value === null || ! array_key_exists('limit', $value)) {
            return 0;
        }

        $raw = $value['limit'];
        if ($raw === null) {
            return PHP_INT_MAX;
        }

        if (is_int($raw)) {
            return max(0, $raw);
        }

        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return 0;
    }

    public function resolveUsed(User $user, string $featureKey, string $kind): int
    {
        return match ($kind) {
            'period' => $this->periodUsed($user, $featureKey),
            'inventory' => $this->inventoryUsed($user, $featureKey),
            default => 0,
        };
    }

    public function remaining(int $limit, int $used): int
    {
        if ($limit === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        return max(0, $limit - max(0, $used));
    }

    private function periodUsed(User $user, string $featureKey): int
    {
        $subscription = $this->entitlements->currentSubscription($user);
        $feature = $this->entitlements->getFeature($user, $featureKey);
        if ($subscription === null || $feature === null) {
            return 0;
        }

        $usage = SubscriptionUsage::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('feature_id', $feature->getKey())
            ->where('period_end', '>=', now())
            ->first();

        return max(0, (int) ($usage === null ? 0 : $usage->used_value));
    }

    private function periodResetAt(User $user, string $featureKey): ?string
    {
        $subscription = $this->entitlements->currentSubscription($user);
        $feature = $this->entitlements->getFeature($user, $featureKey);
        if ($subscription === null || $feature === null) {
            return null;
        }

        $usage = SubscriptionUsage::query()
            ->where('subscription_id', $subscription->getKey())
            ->where('feature_id', $feature->getKey())
            ->where('period_end', '>=', now())
            ->first();

        return $usage?->period_end?->toIso8601String();
    }

    private function inventoryUsed(User $user, string $featureKey): int
    {
        return match ($featureKey) {
            'max_api_keys' => $this->apiKeys->countForUser((string) $user->getKey()),
            'webhook.max_endpoints' => $this->webhooks->activeEndpointCount($user),
            'inbox.max_active' => $this->inboxes->countActiveForUser((string) $user->getKey()),
            default => 0,
        };
    }
}
