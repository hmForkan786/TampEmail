<?php

declare(strict_types=1);

namespace App\Services\Subscription;

use App\Actions\Subscription\CreateSubscriptionAction;
use App\Actions\Subscription\DeleteSubscriptionAction;
use App\Actions\Subscription\FindSubscriptionByIdAction;
use App\Actions\Subscription\FindSubscriptionByUserAndPlanAction;
use App\Actions\Subscription\PaginateSubscriptionsAction;
use App\Actions\Subscription\UpdateSubscriptionAction;
use App\DTOs\Subscription\CreateSubscriptionData;
use App\DTOs\Subscription\SubscriptionFiltersData;
use App\DTOs\Subscription\UpdateSubscriptionData;
use App\Models\Subscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate subscription operations for controllers, API and Filament.
 */
final class SubscriptionService
{
    /**
     * @param CreateSubscriptionAction              $createSubscriptionAction              Create subscription action.
     * @param UpdateSubscriptionAction              $updateSubscriptionAction              Update subscription action.
     * @param DeleteSubscriptionAction              $deleteSubscriptionAction              Delete subscription action.
     * @param FindSubscriptionByIdAction            $findSubscriptionByIdAction            Find subscription by ID action.
     * @param FindSubscriptionByUserAndPlanAction   $findSubscriptionByUserAndPlanAction   Find subscription by user and plan action.
     * @param PaginateSubscriptionsAction           $paginateSubscriptionsAction           Paginate subscriptions action.
     */
    public function __construct(
        private readonly CreateSubscriptionAction $createSubscriptionAction,
        private readonly UpdateSubscriptionAction $updateSubscriptionAction,
        private readonly DeleteSubscriptionAction $deleteSubscriptionAction,
        private readonly FindSubscriptionByIdAction $findSubscriptionByIdAction,
        private readonly FindSubscriptionByUserAndPlanAction $findSubscriptionByUserAndPlanAction,
        private readonly PaginateSubscriptionsAction $paginateSubscriptionsAction,
    ) {}

    /**
     * Create and persist a new subscription.
     *
     * @param CreateSubscriptionData $data Validated subscription creation data.
     *
     * @return Subscription The created subscription.
     */
    public function create(CreateSubscriptionData $data): Subscription
    {
        return $this->createSubscriptionAction->execute($data);
    }

    /**
     * Update and persist changes to the given subscription.
     *
     * @param Subscription           $subscription The subscription to update.
     * @param UpdateSubscriptionData $data         Validated subscription update data.
     *
     * @return Subscription The updated subscription.
     */
    public function update(Subscription $subscription, UpdateSubscriptionData $data): Subscription
    {
        return $this->updateSubscriptionAction->execute($subscription, $data);
    }

    /**
     * Delete the given subscription.
     *
     * @param Subscription $subscription The subscription to delete.
     *
     * @return bool Whether the subscription was deleted.
     */
    public function delete(Subscription $subscription): bool
    {
        return $this->deleteSubscriptionAction->execute($subscription);
    }

    /**
     * Find a subscription by its identifier.
     *
     * @param string $id Subscription identifier.
     *
     * @return Subscription|null The matching subscription, if found.
     */
    public function findById(string $id): ?Subscription
    {
        return $this->findSubscriptionByIdAction->execute($id);
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
        return $this->findSubscriptionByUserAndPlanAction->execute($userId, $planId);
    }

    /**
     * Retrieve a paginated list of subscriptions for the given filters.
     *
     * @param SubscriptionFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated subscription results.
     */
    public function paginate(SubscriptionFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateSubscriptionsAction->execute($filters);
    }
}
