<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;

/**
 * Delete an existing feature.
 */
final class DeleteFeatureAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Delete the given feature.
     *
     * @param Feature $feature The feature to delete.
     *
     * @return bool Whether the feature was deleted.
     */
    public function execute(Feature $feature): bool
    {
        return $this->featureRepository->delete($feature);
    }
}
