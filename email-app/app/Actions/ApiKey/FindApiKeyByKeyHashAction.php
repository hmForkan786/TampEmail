<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;

/**
 * Find an existing API key by its hashed secret.
 */
final class FindApiKeyByKeyHashAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Find the API key for the given hashed secret.
     *
     * @param string $keyHash Hashed secret of the API key.
     *
     * @return ApiKey|null The matching API key, if found.
     */
    public function execute(string $keyHash): ?ApiKey
    {
        return $this->apiKeyRepository->findByKeyHash($keyHash);
    }
}
