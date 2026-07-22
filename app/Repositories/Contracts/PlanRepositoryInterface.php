<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Plan\CreatePlanData;
use App\DTOs\Plan\PlanFiltersData;
use App\DTOs\Plan\UpdatePlanData;
use App\Models\Plan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for plan persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Plan, CreatePlanData, UpdatePlanData>
 */
interface PlanRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a plan by its unique slug.
     *
     * @param string $slug Unique plan business identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function findBySlug(string $slug): ?Plan;

    /**
     * Retrieve a paginated list of plans matching the given filters.
     *
     * @param PlanFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated plan results.
     */
    public function paginate(PlanFiltersData $filters): LengthAwarePaginator;
}
