<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\DTOs\Feature\FeatureFiltersData;
use App\Repositories\Contracts\FeatureRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of features matching the given filters.
 */
final class PaginateFeaturesAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Retrieve a paginated list of features for the given filters.
     *
     * @param FeatureFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated feature results.
     */
    public function execute(FeatureFiltersData $filters): LengthAwarePaginator
    {
        return $this->featureRepository->paginate($filters);
    }
}
