<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Exceptions\ApiKeyScopeNotAllowedException;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyScopeRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Update an existing API key from partial input data.
 *
 * Lock order inside the transaction when permissions are supplied:
 * 1. lock owning user row
 * 2. lock API key row
 * 3. normalize and authorize scopes against the locked owner
 * 4. persist the update
 *
 * When permissions are absent, existing scopes are left unchanged and no
 * scope authorization runs.
 */
final class UpdateApiKeyAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Update and persist changes to the given API key.
     *
     * @param ApiKey           $apiKey The API key to update.
     * @param UpdateApiKeyData $data   Validated API key update data.
     *
     * @return ApiKey The updated API key.
     *
     * @throws InvalidApiKeyScopeException When permissions contain invalid scopes.
     * @throws ApiKeyScopeNotAllowedException When the owner may not hold a requested scope.
     */
    public function execute(ApiKey $apiKey, UpdateApiKeyData $data): ApiKey
    {
        return DB::transaction(function () use ($apiKey, $data): ApiKey {
            if ($data->permissions === null) {
                return $this->apiKeyRepository->update($apiKey, $data);
            }

            $owner = User::query()
                ->whereKey($apiKey->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedKey = ApiKey::query()
                ->whereKey($apiKey->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $data = $data->withPermissions(
                ApiKeyScopeRegistry::authorizeForOwner($owner, $data->permissions) ?? []
            );

            return $this->apiKeyRepository->update($lockedKey, $data);
        });
    }
}
