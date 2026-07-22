<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

/**
 * Find an existing subscription by its identifier.
 */
final class FindSubscriptionByIdAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Find the subscription for the given identifier.
     *
     * @param string $id Subscription identifier.
     *
     * @return Subscription|null The matching subscription, if found.
     */
    public function execute(string $id): ?Subscription
    {
        return $this->subscriptionRepository->findById($id);
    }
}
