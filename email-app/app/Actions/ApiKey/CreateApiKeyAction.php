<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\CreateApiKeyData;
use App\Models\ApiKey;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;

/**
 * Create and persist a new API key from validated input data.
 */
final class CreateApiKeyAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository API key persistence contract.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
    ) {}

    /**
     * Create and persist a new API key.
     *
     * @param CreateApiKeyData $data Validated API key creation data.
     *
     * @return ApiKey The created API key.
     */
    public function execute(CreateApiKeyData $data): ApiKey
    {
        return $this->apiKeyRepository->create($data);
    }
}
