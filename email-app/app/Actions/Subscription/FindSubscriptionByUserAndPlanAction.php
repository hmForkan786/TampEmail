<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

/**
 * Find an existing subscription by owning user and billing plan.
 */
final class FindSubscriptionByUserAndPlanAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Find the subscription for the given user and plan.
     *
     * @param string $userId Owning user UUID.
     * @param string $planId Billing plan UUID.
     *
     * @return Subscription|null The matching subscription, if found.
     */
    public function execute(string $userId, string $planId): ?Subscription
    {
        return $this->subscriptionRepository->findByUserAndPlan($userId, $planId);
    }
}
