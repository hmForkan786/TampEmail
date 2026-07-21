<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

/**
 * Find an existing plan by its identifier.
 */
final class FindPlanByIdAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Find the plan for the given identifier.
     *
     * @param string $id Plan identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function execute(string $id): ?Plan
    {
        return $this->planRepository->findById($id);
    }
}
