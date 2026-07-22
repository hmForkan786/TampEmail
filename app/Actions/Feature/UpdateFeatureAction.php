<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\DTOs\Feature\UpdateFeatureData;
use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;

/**
 * Update an existing feature from partial input data.
 */
final class UpdateFeatureAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Update and persist changes to the given feature.
     *
     * @param Feature           $feature The feature to update.
     * @param UpdateFeatureData $data    Validated feature update data.
     *
     * @return Feature The updated feature.
     */
    public function execute(Feature $feature, UpdateFeatureData $data): Feature
    {
        return $this->featureRepository->update($feature, $data);
    }
}
