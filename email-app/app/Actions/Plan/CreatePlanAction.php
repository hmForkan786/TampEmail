<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\DTOs\Plan\CreatePlanData;
use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

/**
 * Create and persist a new plan from validated input data.
 */
final class CreatePlanAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Create and persist a new plan.
     *
     * @param CreatePlanData $data Validated plan creation data.
     *
     * @return Plan The created plan.
     */
    public function execute(CreatePlanData $data): Plan
    {
        return $this->planRepository->create($data);
    }
}
