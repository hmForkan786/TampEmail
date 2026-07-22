<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\DTOs\Feature\CreateFeatureData;
use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;

/**
 * Create and persist a new feature from validated input data.
 */
final class CreateFeatureAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Create and persist a new feature.
     *
     * @param CreateFeatureData $data Validated feature creation data.
     *
     * @return Feature The created feature.
     */
    public function execute(CreateFeatureData $data): Feature
    {
        return $this->featureRepository->create($data);
    }
}
