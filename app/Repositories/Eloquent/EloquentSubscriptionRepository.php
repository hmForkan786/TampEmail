<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\SubscriptionFiltersData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
     * Find Active and Trial subscriptions for the given user.
     *
     * @param string $userId Owning user UUID.
     *
     * @return Collection<int, Subscription> Eligible subscriptions.
     */
    public function findEligibleForUser(string $userId): Collection
    {
        return $this->eligibleForUserQuery($userId)->get();
    }

    /**
     * Find and lock Active and Trial subscriptions for the given user.
     *
     * @param string $userId Owning user UUID.
     *
     * @return Collection<int, Subscription> Locked eligible subscriptions.
     */
    public function findEligibleForUserForUpdate(string $userId): Collection
    {
        return $this->eligibleForUserQuery($userId)
            ->lockForUpdate()
            ->get();
    }

    /**
     * Build the deterministic eligible-subscription query for a user.
     *
     * Ordering: Active before Trial, then latest starts_at, then latest
     * created_at, then highest id.
     *
     * @param string $userId Owning user UUID.
     *
     * @return Builder<Subscription> The prepared query.
     */
    private function eligibleForUserQuery(string $userId): Builder
    {
        return $this->model()->newQuery()
            ->where('user_id', $userId)
            ->whereIn('status', [
                SubscriptionStatus::Active,
                SubscriptionStatus::Trial,
            ])
            ->orderByRaw('case when status = ? then 0 else 1 end', [SubscriptionStatus::Active->value])
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('status', 'like', "%{$search}%")
                    ->orWhere('billing_cycle', 'like', "%{$search}%")
                    ->orWhere('currency', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
