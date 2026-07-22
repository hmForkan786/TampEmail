<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

/**
 * Update an existing subscription from partial input data.
 */
final class UpdateSubscriptionAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Update and persist changes to the given subscription.
     *
     * @param Subscription           $subscription The subscription to update.
     * @param UpdateSubscriptionData $data         Validated subscription update data.
     *
     * @return Subscription The updated subscription.
     */
    public function execute(Subscription $subscription, UpdateSubscriptionData $data): Subscription
    {
        return $this->subscriptionRepository->update($subscription, $data);
    }
}
