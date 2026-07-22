<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;

/**
 * Find an existing API key by its identifier.
 */
final class FindApiKeyByIdAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Find the API key for the given identifier.
     *
     * @param string $id API key identifier.
     *
     * @return ApiKey|null The matching API key, if found.
     */
    public function execute(string $id): ?ApiKey
    {
        return $this->apiKeyRepository->findById($id);
    }
}
