<?php

declare(strict_types=1);

namespace App\Actions\Plan;

use App\DTOs\Plan\PlanFiltersData;
use App\Repositories\Contracts\PlanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of plans matching the given filters.
 */
final class PaginatePlansAction
{
    /**
     * @param PlanRepositoryInterface $planRepository Plan persistence contract.
     */
    public function __construct(
        private readonly PlanRepositoryInterface $planRepository,
    ) {}

    /**
     * Retrieve a paginated list of plans for the given filters.
     *
     * @param PlanFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated plan results.
     */
    public function execute(PlanFiltersData $filters): LengthAwarePaginator
    {
        return $this->planRepository->paginate($filters);
    }
}
