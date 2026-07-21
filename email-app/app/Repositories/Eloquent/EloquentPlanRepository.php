<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Plan\CreatePlanData;
use App\DTOs\Plan\PlanFiltersData;
use App\DTOs\Plan\UpdatePlanData;
use App\Models\Plan;
use App\Repositories\Contracts\PlanRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for plans.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Plan, CreatePlanData, UpdatePlanData>
 */
final class EloquentPlanRepository extends BaseEloquentRepository implements PlanRepositoryInterface
{
    /**
     * @return Plan
     */
    protected function model(): Plan
    {
        return new Plan;
    }

    /**
     * Find a plan by its unique slug.
     *
     * @param string $slug Unique plan business identifier.
     *
     * @return Plan|null The matching plan, if found.
     */
    public function findBySlug(string $slug): ?Plan
    {
        return $this->model()->newQuery()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Retrieve a paginated list of plans matching the given filters.
     *
     * @param PlanFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated plan results.
     */
    public function paginate(PlanFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }

        if ($filters->isFree !== null) {
            $query->where('is_free', $filters->isFree);
        }

        if ($filters->currency !== null) {
            $query->where('currency', $filters->currency);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($filters->hasSorting()) {
            $query->orderBy($filters->sortBy, $filters->sortDirection);
        }

        return $query->paginate($filters->perPage);
    }
}
