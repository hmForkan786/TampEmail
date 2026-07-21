<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

/**
 * Delete an existing subscription.
 */
final class DeleteSubscriptionAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Delete the given subscription.
     *
     * @param Subscription $subscription The subscription to delete.
     *
     * @return bool Whether the subscription was deleted.
     */
    public function execute(Subscription $subscription): bool
    {
        return $this->subscriptionRepository->delete($subscription);
    }
}
