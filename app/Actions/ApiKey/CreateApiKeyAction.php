<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\ApiKeyIssuanceResult;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\Exceptions\ApiKeyOwnerRequiredException;
use App\Exceptions\ApiKeyQuotaExceededException;
use App\Exceptions\ApiKeyScopeNotAllowedException;
use App\Exceptions\InvalidApiKeyScopeException;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyScopeRegistry;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\Audit\AuditLogWriter;
use App\Services\Commercial\CommercialQuotaResolver;
use App\Services\Commercial\CommercialThresholdNotificationService;
use App\Services\Entitlement\EntitlementService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Create and persist a new API key from validated input data.
 *
 * Lock order inside the transaction:
 * 1. lock owning user row
 * 2. normalize and authorize scopes against the locked owner
 * 3. enforce quota
 * 4. persist the API key with normalized permissions
 */
final class CreateApiKeyAction
{
    /**
     * @param  ApiKeyRepositoryInterface  $apiKeyRepository  API key persistence contract.
     * @param  EntitlementService  $entitlementService  Feature entitlement resolution service.
     */
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly EntitlementService $entitlementService,
        private readonly ApiKeyTokenGenerator $tokenGenerator,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * Create and persist a new API key.
     *
     * @param  CreateApiKeyData  $data  Validated API key creation data.
     * @param  User|null  $user  Authenticated user for quota enforcement, if any.
     * @return ApiKey The created API key.
     *
     * @throws ApiKeyQuotaExceededException When the user's API key quota is exhausted.
     * @throws InvalidApiKeyScopeException When permissions contain invalid scopes.
     * @throws ApiKeyScopeNotAllowedException When the owner may not hold a requested scope.
     */
    public function execute(CreateApiKeyData $data, ?User $user = null): ApiKey
    {
        try {
            return DB::transaction(function () use ($data, $user): ApiKey {
                $lockedUser = $this->resolveLockedUser($data->userId, $user);
                $data = $data->withPermissions(
                    ApiKeyScopeRegistry::authorizeForOwner($lockedUser, $data->permissions)
                );
                $this->enforceQuota($lockedUser);

                if ($data->userId === '') {
                    $data = $data->withUserId((string) $lockedUser->getKey());
                }

                return $this->apiKeyRepository->create($data);
            });
        } catch (ApiKeyQuotaExceededException $exception) {
            $this->auditQuotaDenial($exception);

            throw $exception;
        }
    }

    /**
     * Issue a canonical key and return its plaintext token once.
     *
     * @param  list<mixed>|null  $permissions
     * @param  array<string, mixed>|null  $metadata
     *
     * @throws ApiKeyQuotaExceededException
     * @throws InvalidApiKeyScopeException
     * @throws ApiKeyScopeNotAllowedException
     */
    public function issue(
        string $userId,
        string $name,
        ?array $permissions = null,
        int $rateLimitPerMinute = 60,
        ?CarbonInterface $expiresAt = null,
        ?array $metadata = null,
        ?User $user = null,
    ): ApiKeyIssuanceResult {
        try {
            $lockedUser = null;
            $result = DB::transaction(function () use ($userId, $name, $permissions, $rateLimitPerMinute, $expiresAt, $metadata, $user, &$lockedUser): ApiKeyIssuanceResult {
                $lockedUser = $this->resolveLockedUser($userId, $user);
                $authorizedPermissions = ApiKeyScopeRegistry::authorizeForOwner($lockedUser, $permissions);
                $this->enforceQuota($lockedUser);

                $credentials = $this->tokenGenerator->generate();
                $apiKey = $this->apiKeyRepository->create(new CreateApiKeyData(
                    userId: (string) $lockedUser->getKey(),
                    name: $name,
                    keyPrefix: $credentials['key_prefix'],
                    keyHash: $credentials['key_hash'],
                    permissions: $authorizedPermissions,
                    rateLimitPerMinute: $rateLimitPerMinute,
                    expiresAt: $expiresAt,
                    revokedAt: null,
                    metadata: $metadata,
                ));

                return new ApiKeyIssuanceResult($apiKey, $credentials['plain_token']);
            });
            if ($lockedUser instanceof User) {
                $this->notifyInventoryThreshold($lockedUser, 'max_api_keys');
            }

            return $result;
        } catch (ApiKeyQuotaExceededException $exception) {
            $this->auditQuotaDenial($exception);

            throw $exception;
        }
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

        if ($ownerId === '') {
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
     * @param  User  $user  The user to enforce the quota for.
     *
     * @throws ApiKeyQuotaExceededException When the user's API key quota is exhausted.
     */
    private function enforceQuota(User $user): void
    {
        // Legacy/internal callers that do not participate in the commercial
        // catalogue retain their existing unlimited behavior. Commercial
        // plans always carry an explicit mapping and a mapped zero denies.
        $value = $this->entitlementService->featureValue($user, 'max_api_keys');
        if ($value === null || ! array_key_exists('limit', $value) || $value['limit'] === null) {
            return;
        }

        $limit = $this->entitlementService->limit($user, 'max_api_keys');
        $count = $this->apiKeyRepository->countForUser($user->id);

        if ($count >= $limit) {
            throw new ApiKeyQuotaExceededException(
                userId: (string) $user->getKey(),
                limit: $limit,
                used: $count,
            );
        }
    }

    private function auditQuotaDenial(ApiKeyQuotaExceededException $exception): void
    {
        if ($exception->userId === null || $exception->limit === null || $exception->used === null) {
            return;
        }

        $this->audit->write('commercial.api_key_limit_reached', $exception->userId, null, null, null, [
            'feature' => 'max_api_keys',
            'limit' => $exception->limit,
            'used' => $exception->used,
            'remaining' => 0,
        ]);
    }

    private function notifyInventoryThreshold(User $user, string $featureKey): void
    {
        $resolver = app(CommercialQuotaResolver::class);
        $limit = $resolver->resolveLimit($user, $featureKey);
        if ($limit === null || $limit === PHP_INT_MAX || $limit <= 0) {
            return;
        }

        app(CommercialThresholdNotificationService::class)->evaluate(
            $user,
            $featureKey,
            $resolver->resolveUsed($user, $featureKey, 'inventory'),
            $limit,
            'inventory',
        );
    }
}
