<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\CreateApiKeyData;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Entitlement\EntitlementService;

/**
 * Create and persist a new API key from validated input data.
 *
 * Enforces the max_api_keys plan entitlement before persistence when an
 * authenticated user context is provided.
 */
final class CreateApiKeyAction
{
    /**
     * @param ApiKeyRepositoryInterface $apiKeyRepository   API key persistence contract.
     * @param EntitlementService        $entitlementService Feature entitlement resolution service.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly EntitlementService $entitlementService,
    ) {}

    /**
     * Create and persist a new API key.
     *
     * @param CreateApiKeyData $data Validated API key creation data.
     * @param User|null        $user Authenticated user for quota enforcement, if any.
     *
     * @return ApiKey The created API key.
     *
     * @throws ApiKeyQuotaExceededException When the user's API key quota is exhausted.
     */
    public function execute(CreateApiKeyData $data, ?User $user = null): ApiKey
    {
        if ($user !== null) {
            $this->enforceQuota($user);
        }

        return $this->apiKeyRepository->create($data);
    }

    /**
     * Enforce the max_api_keys entitlement for the given user.
     *
     * Unlimited plans (no resolved value, missing limit key, or null limit)
     * skip counting entirely.
     *
     * @param User $user The user to enforce the quota for.
     *
     * @throws ApiKeyQuotaExceededException When the user's API key quota is exhausted.
     */
    private function enforceQuota(User $user): void
    {
        $value = $this->entitlementService->featureValue($user, 'max_api_keys');

        if ($value === null || ! array_key_exists('limit', $value) || $value['limit'] === null) {
            return;
        }

        $count = $this->apiKeyRepository->countForUser($user->id);

        if ($count >= (int) $value['limit']) {
            throw new ApiKeyQuotaExceededException;
        }
    }
}
