<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;

/**
 * Find an existing plan by its unique slug.
 */
final class FindPlanBySlugAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Find the plan for the given slug.
     *
     * @param string $slug Unique plan business identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function execute(string $slug): ?Plan
    {
        return $this->planRepository->findBySlug($slug);
    }
}
