<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\DTOs\Subscription\SubscriptionFiltersData;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of subscriptions matching the given filters.
 */
final class PaginateSubscriptionsAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Retrieve a paginated list of subscriptions for the given filters.
     *
     * @param SubscriptionFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated subscription results.
     */
    public function execute(SubscriptionFiltersData $filters): LengthAwarePaginator
    {
        return $this->subscriptionRepository->paginate($filters);
    }
}
