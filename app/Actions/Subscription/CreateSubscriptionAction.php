<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Enums\SubscriptionStatus;
use App\Exceptions\FutureDatedSubscriptionNotAllowedException;
use App\Exceptions\SubscriptionLifecycleConflictException;
use App\Models\Subscription;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Create and persist a new subscription from validated input data.
 *
 * Enforces the subscription lifecycle policy inside a transaction: no
 * future-dated subscriptions, a new Active subscription supersedes existing
 * eligible subscriptions, and a new Trial is rejected when any eligible
 * subscription exists.
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
     *
     * @throws FutureDatedSubscriptionNotAllowedException When starts_at is in the future.
     * @throws SubscriptionLifecycleConflictException     When lifecycle rules reject the creation.
     */
    public function execute(CreateSubscriptionData $data): Subscription
    {
        return DB::transaction(function () use ($data): Subscription {
            if ($data->startsAt->isAfter(now())) {
                throw new FutureDatedSubscriptionNotAllowedException;
            }

            $eligible = $this->subscriptionRepository->findEligibleForUserForUpdate($data->userId);

            if ($data->status === SubscriptionStatus::Trial && $eligible->isNotEmpty()) {
                throw new SubscriptionLifecycleConflictException;
            }

            if ($data->status === SubscriptionStatus::Active) {
                foreach ($eligible as $subscription) {
                    $this->subscriptionRepository->update(
                        $subscription,
                        UpdateSubscriptionData::fromArray([
                            'status' => SubscriptionStatus::Cancelled,
                            'cancelled_at' => now(),
                            'ends_at' => now(),
                        ]),
                    );
                }
            }

            return $this->subscriptionRepository->create($data);
        });
    }
}
