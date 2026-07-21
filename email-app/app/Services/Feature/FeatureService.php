<?php

declare(strict_types=1);

namespace App\Services\Feature;

use App\Actions\Feature\CreateFeatureAction;
use App\Actions\Feature\DeleteFeatureAction;
use App\Actions\Feature\FindFeatureByIdAction;
use App\Actions\Feature\FindFeatureByKeyAction;
use App\Actions\Feature\PaginateFeaturesAction;
use App\Actions\Feature\UpdateFeatureAction;
use App\DTOs\Feature\CreateFeatureData;
use App\DTOs\Feature\FeatureFiltersData;
use App\DTOs\Feature\UpdateFeatureData;
use App\Models\Feature;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate feature operations for controllers, API and Filament.
 */
final class FeatureService
{
    /**
     * @param CreateFeatureAction    $createFeatureAction    Create feature action.
     * @param UpdateFeatureAction    $updateFeatureAction    Update feature action.
     * @param DeleteFeatureAction    $deleteFeatureAction    Delete feature action.
     * @param FindFeatureByIdAction  $findFeatureByIdAction  Find feature by ID action.
     * @param FindFeatureByKeyAction $findFeatureByKeyAction Find feature by key action.
     * @param PaginateFeaturesAction $paginateFeaturesAction Paginate features action.
     */
    public function __construct(
        private readonly CreateFeatureAction $createFeatureAction,
        private readonly UpdateFeatureAction $updateFeatureAction,
        private readonly DeleteFeatureAction $deleteFeatureAction,
        private readonly FindFeatureByIdAction $findFeatureByIdAction,
        private readonly FindFeatureByKeyAction $findFeatureByKeyAction,
        private readonly PaginateFeaturesAction $paginateFeaturesAction,
    ) {}

    /**
     * Create and persist a new feature.
     *
     * @param CreateFeatureData $data Validated feature creation data.
     *
     * @return Feature The created feature.
     */
    public function create(CreateFeatureData $data): Feature
    {
        return $this->createFeatureAction->execute($data);
    }

    /**
     * Update and persist changes to the given feature.
     *
     * @param Feature           $feature The feature to update.
     * @param UpdateFeatureData $data    Validated feature update data.
     *
     * @return Feature The updated feature.
     */
    public function update(Feature $feature, UpdateFeatureData $data): Feature
    {
        return $this->updateFeatureAction->execute($feature, $data);
    }

    /**
     * Delete the given feature.
     *
     * @param Feature $feature The feature to delete.
     *
     * @return bool Whether the feature was deleted.
     */
    public function delete(Feature $feature): bool
    {
        return $this->deleteFeatureAction->execute($feature);
    }

    /**
     * Find a feature by its identifier.
     *
     * @param string $id Feature identifier.
     *
     * @return Feature|null The matching feature, if found.
     */
    public function findById(string $id): ?Feature
    {
        return $this->findFeatureByIdAction->execute($id);
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
        return $this->findFeatureByKeyAction->execute($key);
    }

    /**
     * Retrieve a paginated list of features for the given filters.
     *
     * @param FeatureFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated feature results.
     */
    public function paginate(FeatureFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateFeaturesAction->execute($filters);
    }
}
