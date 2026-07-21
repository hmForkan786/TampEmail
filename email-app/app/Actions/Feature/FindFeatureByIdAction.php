<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;

/**
 * Find an existing feature by its identifier.
 */
final class FindFeatureByIdAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Find the feature for the given identifier.
     *
     * @param string $id Feature identifier.
     *
     * @return Feature|null The matching feature, if found.
     */
    public function execute(string $id): ?Feature
    {
        return $this->featureRepository->findById($id);
    }
}
