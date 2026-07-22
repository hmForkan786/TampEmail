<?php

declare(strict_types=1);

namespace App\DTOs\Subscription;

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;

/**
 * Filter and pagination state for querying subscriptions.
 */
final readonly class SubscriptionFiltersData
{
    /**
     * @param string|null $userId Filter by owning user UUID.
     * @param string|null $planId Filter by billing plan UUID.
     * @param SubscriptionStatus|null $status Filter by subscription status.
     * @param BillingCycle|null $billingCycle Filter by billing cycle.
     * @param bool|null $autoRenew Filter by auto-renew setting.
     * @param string|null $search Free-text search term.
     * @param int $perPage Number of results per page.
     * @param string $sortBy Column to sort by.
     * @param string $sortDirection Sort direction (asc or desc).
     */
    public function __construct(
        public ?string $userId,
        public ?string $planId,
        public ?SubscriptionStatus $status,
        public ?BillingCycle $billingCycle,
        public ?bool $autoRenew,
        public ?string $search,
        public int $perPage,
        public string $sortBy,
        public string $sortDirection,
    ) {}

    /**
     * Create a filter DTO instance from an array payload.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $status = null;

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $status = $data['status'] instanceof SubscriptionStatus
                ? $data['status']
                : SubscriptionStatus::from((string) $data['status']);
        }

        $billingCycle = null;

        if (array_key_exists('billing_cycle', $data) && $data['billing_cycle'] !== null) {
            $billingCycle = $data['billing_cycle'] instanceof BillingCycle
                ? $data['billing_cycle']
                : BillingCycle::from((string) $data['billing_cycle']);
        }

        return new self(
            userId: array_key_exists('user_id', $data)
                ? ($data['user_id'] !== null ? (string) $data['user_id'] : null)
                : null,
            planId: array_key_exists('plan_id', $data)
                ? ($data['plan_id'] !== null ? (string) $data['plan_id'] : null)
                : null,
            status: $status,
            billingCycle: $billingCycle,
            autoRenew: array_key_exists('auto_renew', $data)
                ? ($data['auto_renew'] !== null ? (bool) $data['auto_renew'] : null)
                : null,
            search: array_key_exists('search', $data)
                ? ($data['search'] !== null ? (string) $data['search'] : null)
                : null,
            perPage: array_key_exists('per_page', $data)
                ? (int) $data['per_page']
                : 15,
            sortBy: array_key_exists('sort_by', $data)
                ? (string) $data['sort_by']
                : 'created_at',
            sortDirection: array_key_exists('sort_direction', $data)
                ? (string) $data['sort_direction']
                : 'desc',
        );
    }

    /**
     * Determine whether a search term is present.
     */
    public function hasSearch(): bool
    {
        return $this->search !== null && $this->search !== '';
    }

    /**
     * Determine whether sorting parameters are present.
     */
    public function hasSorting(): bool
    {
        return $this->sortBy !== ''
            && $this->sortDirection !== '';
    }

    /**
     * Get pagination settings for the query.
     *
     * @return array{per_page: int}
     */
    public function pagination(): array
    {
        return [
            'per_page' => $this->perPage,
        ];
    }
}
