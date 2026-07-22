<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;

/**
 * Delete an existing API key.
 */
final class DeleteApiKeyAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Delete the given API key.
     *
     * @param ApiKey $apiKey The API key to delete.
     *
     * @return bool Whether the API key was deleted.
     */
    public function execute(ApiKey $apiKey): bool
    {
        return $this->apiKeyRepository->delete($apiKey);
    }
}
