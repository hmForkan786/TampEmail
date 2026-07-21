<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\SubscriptionFiltersData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
     * Retrieve a paginated list of subscriptions matching the given filters.
     *
     * @param SubscriptionFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated subscription results.
     */
    public function paginate(SubscriptionFiltersData $filters): LengthAwarePaginator;
}
