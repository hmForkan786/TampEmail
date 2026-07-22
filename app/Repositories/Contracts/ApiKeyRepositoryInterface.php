<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\DTOs\ApiKey\ApiKeyFiltersData;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Models\ApiKey;
use DateTimeInterface;
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

    public function findActiveByPrefixAndHash(string $keyPrefix, string $keyHash): ?ApiKey;

    /**
     * Count the non-revoked API keys owned by the given user.
     *
     * @param string $userId Owning user UUID.
     *
     * @return int Number of non-revoked API keys owned by the user.
     */
    public function countForUser(string $userId): int;

    /**
     * Revoke non-revoked keys for a user that store any of the given exact scopes.
     *
     * Matching uses JSON array exact-value containment (not SQL substring wildcards).
     * Already-revoked keys are left unchanged. Returns the number of rows revoked.
     *
     * @param  list<string>  $scopes Canonical scope values to match.
     */
    public function revokeUnrevokedForUserWithAnyScope(
        string $userId,
        array $scopes,
        DateTimeInterface $revokedAt,
    ): int;

    /**
     * Revoke every non-revoked API key owned by the user.
     *
     * Expired but non-revoked keys are included. Already-revoked timestamps are
     * not overwritten. Returns the number of rows revoked.
     */
    public function revokeAllUnrevokedForUser(
        string $userId,
        DateTimeInterface $revokedAt,
    ): int;

    /**
     * Retrieve a paginated list of API keys matching the given filters.
     *
     * @param ApiKeyFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated API key results.
     */
    public function paginate(ApiKeyFiltersData $filters): LengthAwarePaginator;
}
