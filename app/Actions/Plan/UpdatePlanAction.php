<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\DTOs\Plan\UpdatePlanData;
use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

/**
 * Update an existing plan from partial input data.
 */
final class UpdatePlanAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Update and persist changes to the given plan.
     *
     * @param Plan           $plan The plan to update.
     * @param UpdatePlanData $data Validated plan update data.
     *
     * @return Plan The updated plan.
     */
    public function execute(Plan $plan, UpdatePlanData $data): Plan
    {
        return $this->planRepository->update($plan, $data);
    }
}
