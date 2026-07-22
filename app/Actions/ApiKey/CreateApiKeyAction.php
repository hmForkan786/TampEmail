<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\CreateApiKeyData;
use App\DTOs\ApiKey\ApiKeyIssuanceResult;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Exceptions\ApiKeyOwnerRequiredException;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\Entitlement\EntitlementService;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
        private readonly ApiKeyTokenGenerator $tokenGenerator,
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
        return DB::transaction(function () use ($data, $user): ApiKey {
            $lockedUser = $this->resolveLockedUser($data->userId, $user);
            $this->enforceQuota($lockedUser);

            if ($data->userId === '') {
                $data = $data->withUserId((string) $lockedUser->getKey());
            }

            return $this->apiKeyRepository->create($data);
        });
    }

    /** Issue a canonical key and return its plaintext token once. */
    public function issue(
        string $userId,
        string $name,
        ?array $permissions = null,
        int $rateLimitPerMinute = 60,
        ?\Carbon\CarbonInterface $expiresAt = null,
        ?array $metadata = null,
        ?User $user = null,
    ): ApiKeyIssuanceResult {
        return DB::transaction(function () use ($userId, $name, $permissions, $rateLimitPerMinute, $expiresAt, $metadata, $user): ApiKeyIssuanceResult {
            $lockedUser = $this->resolveLockedUser($userId, $user);

            $this->enforceQuota($lockedUser);

            $credentials = $this->tokenGenerator->generate();
            $apiKey = $this->apiKeyRepository->create(new CreateApiKeyData(
                userId: (string) $lockedUser->getKey(),
                name: $name,
                keyPrefix: $credentials['key_prefix'],
                keyHash: $credentials['key_hash'],
                permissions: $permissions,
                rateLimitPerMinute: $rateLimitPerMinute,
                expiresAt: $expiresAt,
                revokedAt: null,
                metadata: $metadata,
            ));

            return new ApiKeyIssuanceResult($apiKey, $credentials['plain_token']);
        });
    }

    /**
     * Resolve and lock the owning user before quota calculation.
     */
    private function resolveLockedUser(string $userId, ?User $user): User
    {
        if ($user !== null && $userId !== '' && $user->getKey() !== $userId) {
            throw new InvalidArgumentException('The API key user does not match the payload user.');
        }

        $ownerId = $user?->getKey() ?? $userId;

        if ($ownerId === null || $ownerId === '') {
            throw new ApiKeyOwnerRequiredException;
        }

        return User::query()
            ->whereKey($ownerId)
            ->lockForUpdate()
            ->firstOrFail();
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
