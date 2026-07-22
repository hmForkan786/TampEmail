<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

/**
 * Delete an existing plan.
 */
final class DeletePlanAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Delete the given plan.
     *
     * @param Plan $plan The plan to delete.
     *
     * @return bool Whether the plan was deleted.
     */
    public function execute(Plan $plan): bool
    {
        return $this->planRepository->delete($plan);
    }
}
