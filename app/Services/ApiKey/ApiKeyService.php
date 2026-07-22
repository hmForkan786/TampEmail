<?php

declare(strict_types=1);

namespace App\Services\ApiKey;

use App\Actions\ApiKey\CreateApiKeyAction;
use App\Actions\ApiKey\DeleteApiKeyAction;
use App\Actions\ApiKey\FindApiKeyByIdAction;
use App\Actions\ApiKey\FindApiKeyByKeyHashAction;
use App\Actions\ApiKey\PaginateApiKeysAction;
use App\Actions\ApiKey\UpdateApiKeyAction;
use App\DTOs\ApiKey\ApiKeyFiltersData;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\UpdateApiKeyData;
use App\DTOs\ApiKey\ApiKeyIssuanceResult;
use App\Models\ApiKey;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Orchestrate API key operations for controllers, API and Filament.
 */
final class ApiKeyService
{
    /**
     * @param CreateApiKeyAction        $createApiKeyAction        Create API key action.
     * @param UpdateApiKeyAction        $updateApiKeyAction        Update API key action.
     * @param DeleteApiKeyAction        $deleteApiKeyAction        Delete API key action.
     * @param FindApiKeyByIdAction      $findApiKeyByIdAction      Find API key by ID action.
     * @param FindApiKeyByKeyHashAction $findApiKeyByKeyHashAction Find API key by key hash action.
     * @param PaginateApiKeysAction     $paginateApiKeysAction     Paginate API keys action.
     */
    public function __construct(
        private readonly CreateApiKeyAction $createApiKeyAction,
        private readonly UpdateApiKeyAction $updateApiKeyAction,
        private readonly DeleteApiKeyAction $deleteApiKeyAction,
        private readonly FindApiKeyByIdAction $findApiKeyByIdAction,
        private readonly FindApiKeyByKeyHashAction $findApiKeyByKeyHashAction,
        private readonly PaginateApiKeysAction $paginateApiKeysAction,
    ) {}

    /**
     * Create and persist a new API key.
     *
     * @param CreateApiKeyData $data Validated API key creation data.
     *
     * @return ApiKey The created API key.
     */
    public function create(CreateApiKeyData $data): ApiKey
    {
        return $this->createApiKeyAction->execute($data);
    }

    public function issue(
        string $userId,
        string $name,
        ?array $permissions = null,
        int $rateLimitPerMinute = 60,
        ?CarbonInterface $expiresAt = null,
        ?array $metadata = null,
        ?User $user = null,
    ): ApiKeyIssuanceResult {
        return $this->createApiKeyAction->issue($userId, $name, $permissions, $rateLimitPerMinute, $expiresAt, $metadata, $user);
    }

    /**
     * Update and persist changes to the given API key.
     *
     * @param ApiKey           $apiKey The API key to update.
     * @param UpdateApiKeyData $data   Validated API key update data.
     *
     * @return ApiKey The updated API key.
     */
    public function update(ApiKey $apiKey, UpdateApiKeyData $data): ApiKey
    {
        return $this->updateApiKeyAction->execute($apiKey, $data);
    }

    /**
     * Delete the given API key.
     *
     * @param ApiKey $apiKey The API key to delete.
     *
     * @return bool Whether the API key was deleted.
     */
    public function delete(ApiKey $apiKey): bool
    {
        return $this->deleteApiKeyAction->execute($apiKey);
    }

    /**
     * Find an API key by its identifier.
     *
     * @param string $id API key identifier.
     *
     * @return ApiKey|null The matching API key, if found.
     */
    public function findById(string $id): ?ApiKey
    {
        return $this->findApiKeyByIdAction->execute($id);
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
        return $this->findApiKeyByKeyHashAction->execute($keyHash);
    }

    /**
     * Retrieve a paginated list of API keys for the given filters.
     *
     * @param ApiKeyFiltersData $filters Pagination and filter criteria.
     *
     * @return LengthAwarePaginator Paginated API key results.
     */
    public function paginate(ApiKeyFiltersData $filters): LengthAwarePaginator
    {
        return $this->paginateApiKeysAction->execute($filters);
    }
}
