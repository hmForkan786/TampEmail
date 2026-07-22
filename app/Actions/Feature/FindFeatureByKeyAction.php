<?php

declare(strict_types=1);

namespace App\Actions\Feature;

use App\Models\Feature;
use App\Repositories\Contracts\FeatureRepositoryInterface;

/**
 * Find an existing feature by its unique key.
 */
final class FindFeatureByKeyAction
{
    /**
     * @param FeatureRepositoryInterface $featureRepository Feature persistence contract.
     */
    public function __construct(
        private readonly FeatureRepositoryInterface $featureRepository,
    ) {}

    /**
     * Find the feature for the given key.
     *
     * @param string $key Stable machine-readable feature identifier.
     *
     * @return Feature|null The matching feature, if found.
     */
    public function execute(string $key): ?Feature
    {
        return $this->featureRepository->findByKey($key);
    }
}
