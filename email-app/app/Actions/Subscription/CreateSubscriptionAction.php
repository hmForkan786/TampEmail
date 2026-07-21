<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;

/**
 * Create and persist a new subscription from validated input data.
 */
final class CreateSubscriptionAction
{
    /**
     * @param SubscriptionRepositoryInterface $subscriptionRepository Subscription persistence contract.
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
    ) {}

    /**
     * Create and persist a new subscription.
     *
     * @param CreateSubscriptionData $data Validated subscription creation data.
     *
     * @return Subscription The created subscription.
     */
    public function execute(CreateSubscriptionData $data): Subscription
    {
        return $this->subscriptionRepository->create($data);
    }
}
