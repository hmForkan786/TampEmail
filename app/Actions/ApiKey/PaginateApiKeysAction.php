<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\ApiKeyFiltersData;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Retrieve a paginated list of API keys matching the given filters.
 */
final class PaginateApiKeysAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Retrieve a paginated list of API keys for the given filters.
     *
     * @param ApiKeyFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated API key results.
     */
    public function execute(ApiKeyFiltersData $filters): LengthAwarePaginator
    {
        return $this->apiKeyRepository->paginate($filters);
    }
}
