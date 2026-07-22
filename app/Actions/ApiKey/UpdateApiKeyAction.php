<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\UpdateApiKeyData;
use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;

/**
 * Update an existing API key from partial input data.
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
     */
    public function execute(ApiKey $apiKey, UpdateApiKeyData $data): ApiKey
    {
        return $this->apiKeyRepository->update($apiKey, $data);
    }
}
