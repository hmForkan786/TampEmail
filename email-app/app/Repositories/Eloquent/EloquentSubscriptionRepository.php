<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\SubscriptionFiltersData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for subscriptions.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Subscription, CreateSubscriptionData, UpdateSubscriptionData>
 */
final class EloquentSubscriptionRepository extends BaseEloquentRepository implements SubscriptionRepositoryInterface
{
    /**
     * @return Subscription
     */
    protected function model(): Subscription
    {
        return new Subscription;
    }

    /**
     * Find a subscription by owning user and billing plan.
     *
     * @param string $userId Owning user UUID.
     * @param string $planId Billing plan UUID.
     *
     * @return Subscription|null The matching subscription, if found.
     */
    public function findByUserAndPlan(string $userId, string $planId): ?Subscription
    {
        return $this->model()->newQuery()
            ->where('user_id', $userId)
            ->where('plan_id', $planId)
            ->first();
    }

    /**
     * Retrieve a paginated list of subscriptions matching the given filters.
     *
     * @param SubscriptionFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated subscription results.
     */
    public function paginate(SubscriptionFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->userId !== null) {
            $query->where('user_id', $filters->userId);
        }

        if ($filters->planId !== null) {
            $query->where('plan_id', $filters->planId);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status);
        }

        if ($filters->billingCycle !== null) {
            $query->where('billing_cycle', $filters->billingCycle);
        }

        if ($filters->autoRenew === true) {
            $query->where('auto_renew', true);
        }

        if ($filters->autoRenew === false) {
            $query->where('auto_renew', false);
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
