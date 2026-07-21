<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\Feature\CreateFeatureData;
use App\DTOs\Feature\FeatureFiltersData;
use App\DTOs\Feature\UpdateFeatureData;
use App\Models\Feature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for feature persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<Feature, CreateFeatureData, UpdateFeatureData>
 */
interface FeatureRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find a feature by its unique key.
     *
     * @param string $key Stable machine-readable feature identifier.
     *
     * @return Feature|null The matching feature, if found.
     */
    public function findByKey(string $key): ?Feature;

    /**
     * Retrieve a paginated list of features matching the given filters.
     *
     * @param FeatureFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated feature results.
     */
    public function paginate(FeatureFiltersData $filters): LengthAwarePaginator;
}
