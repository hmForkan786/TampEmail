<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DTOs\ApiKey\ApiKeyFiltersData;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Eloquent-backed persistence and query implementation for API keys.
 *
 * Common CRUD operations are inherited from BaseEloquentRepository.
 *
 * @extends BaseEloquentRepository<ApiKey, CreateApiKeyData, UpdateApiKeyData>
 */
final class EloquentApiKeyRepository extends BaseEloquentRepository implements ApiKeyRepositoryInterface
{
    /**
     * @return ApiKey
     */
    protected function model(): ApiKey
    {
        return new ApiKey;
    }

    /**
     * Find an API key by its hashed secret.
     *
     * @param string $keyHash Hashed secret of the API key.
     *
     * @return ApiKey|null The matching API key, if found.
     */
    public function findByKeyHash(string $keyHash): ?ApiKey
    {
        return $this->model()->newQuery()
            ->where('key_hash', $keyHash)
            ->first();
    }

    /**
     * Count the non-revoked API keys owned by the given user.
     *
     * Expiration state is intentionally ignored; expired but non-revoked
     * keys still occupy quota.
     *
     * @param string $userId Owning user UUID.
     *
     * @return int Number of non-revoked API keys owned by the user.
     */
    public function countForUser(string $userId): int
    {
        return $this->model()->newQuery()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->count();
    }

    /**
     * Retrieve a paginated list of API keys matching the given filters.
     *
     * @param ApiKeyFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated API key results.
     */
    public function paginate(ApiKeyFiltersData $filters): LengthAwarePaginator
    {
        $query = $this->model()->newQuery();

        if ($filters->userId !== null) {
            $query->where('user_id', $filters->userId);
        }

        if ($filters->keyPrefix !== null) {
            $query->where('key_prefix', $filters->keyPrefix);
        }

        if ($filters->isRevoked === true) {
            $query->whereNotNull('revoked_at');
        }

        if ($filters->isRevoked === false) {
            $query->whereNull('revoked_at');
        }

        if ($filters->isExpired === true) {
            $query->whereNotNull('expires_at')
                ->where('expires_at', '<=', now());
        }

        if ($filters->isExpired === false) {
            $query->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        }

        if ($filters->hasSearch()) {
            $search = $filters->search;

            $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('key_prefix', 'like', "%{$search}%");
            });
        }

        return $query
            ->orderBy($filters->sortBy, $filters->sortDirection)
            ->paginate($filters->perPage);
    }
}
