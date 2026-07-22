<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\Feature\CreateFeatureData;
use App\DTOs\Feature\FeatureFiltersData;
use App\DTOs\Feature\UpdateFeatureData;
use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for features.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<Feature, CreateFeatureData, UpdateFeatureData>
 */
final class EloquentFeatureRepository extends BaseEloquentRepository implements FeatureRepositoryInterface
{
    /**
     * @return Feature
     */
    protected function model(): Feature
    {
        return new Feature;
    }

    /**
     * Find a feature by its unique key.
     *
     * @param string $key Stable machine-readable feature identifier.
     *
     * @return Feature|null The matching feature, if found.
     */
    public function findByKey(string $key): ?Feature
    {
        return $this->model()->newQuery()
            ->where('key', $key)
            ->first();
    }

    /**
     * Retrieve a paginated list of features matching the given filters.
     *
     * @param FeatureFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated feature results.
     */
    public function paginate(FeatureFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->category !== null) {
            $query->where('category', $filters->category);
        }

        if ($filters->valueType !== null) {
            $query->where('value_type', $filters->valueType);
        }

        if ($filters->isActive !== null) {
            $query->where('is_active', $filters->isActive);
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('key', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($filters->hasSorting()) {
            $query->orderBy($filters->sortBy, $filters->sortDirection);
        }

        return $query->paginate($filters->perPage);
    }
}
