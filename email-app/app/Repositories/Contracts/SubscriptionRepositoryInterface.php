<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\SubscriptionFiltersData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for subscription persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Subscription, CreateSubscriptionData, UpdateSubscriptionData>
 */
interface SubscriptionRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a subscription by owning user and billing plan.
     *
     * @param string $userId Owning user UUID.
     * @param string $planId Billing plan UUID.
     *
     * @return Subscription|null The matching subscription, if found.
     */
    public function findByUserAndPlan(string $userId, string $planId): ?Subscription;

    /**
     * Find Active and Trial subscriptions for the given user.
     *
     * @param string $userId Owning user UUID.
     *
     * @return Collection<int, Subscription> Eligible subscriptions.
     */
    public function findEligibleForUser(string $userId): Collection;

    /**
     * Find and lock Active and Trial subscriptions for the given user.
     *
     * @param string $userId Owning user UUID.
     *
     * @return Collection<int, Subscription> Locked eligible subscriptions.
     */
    public function findEligibleForUserForUpdate(string $userId): Collection;

    /**
     * Retrieve a paginated list of subscriptions matching the given filters.
     *
     * @param SubscriptionFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated subscription results.
     */
    public function paginate(SubscriptionFiltersData $filters): LengthAwarePaginator;
}
