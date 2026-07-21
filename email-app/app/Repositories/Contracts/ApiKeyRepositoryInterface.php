<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\ApiKey\ApiKeyFiltersData;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Models\ApiKey;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Contract for API key persistence operations.
 *
 * Common CRUD methods are inherited from BaseRepositoryInterface.
 * Module-specific lookups and Filter DTO pagination remain here.
 *
 * @extends BaseRepositoryInterface<ApiKey, CreateApiKeyData, UpdateApiKeyData>
 */
interface ApiKeyRepositoryInterface extends BaseRepositoryInterface
{
    /**
     * Find an API key by its hashed secret.
     *
     * @param string $keyHash Hashed secret of the API key.
     *
     * @return ApiKey|null The matching API key, if found.
     */
    public function findByKeyHash(string $keyHash): ?ApiKey;

    /**
     * Retrieve a paginated list of API keys matching the given filters.
     *
     * @param ApiKeyFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated API key results.
     */
    public function paginate(ApiKeyFiltersData $filters): LengthAwarePaginator;
}
