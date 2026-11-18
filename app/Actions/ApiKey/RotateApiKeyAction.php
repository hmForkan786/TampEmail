<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\ApiKeyIssuanceResult;
use App\DTOs\ApiKey\CreateApiKeyData;
use App\Exceptions\ApiKeyRotationNotAllowedException;
use App\Models\ApiKey;
use App\Models\User;
use App\Repositories\Contracts\ApiKeyRepositoryInterface;
use App\Services\ApiKey\ApiKeyTokenGenerator;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Atomically replace one active API key without consuming an extra quota slot.
 *
 * Lock order:
 * 1. lock owner
 * 2. lock source key
 * 3. create replacement
 * 4. revoke source key
 */
final class RotateApiKeyAction
{
    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeyRepository,
        private readonly ApiKeyTokenGenerator $tokenGenerator,
        private readonly AuditLogWriter $audit,
    ) {}

    public function execute(User $owner, ApiKey $apiKey): ApiKeyIssuanceResult
    {
        return DB::transaction(function () use ($owner, $apiKey): ApiKeyIssuanceResult {
            $lockedOwner = User::query()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
            $lockedKey = ApiKey::query()->whereKey($apiKey->getKey())->lockForUpdate()->firstOrFail();

            if ((string) $lockedKey->user_id !== (string) $lockedOwner->getKey()) {
                throw new ApiKeyRotationNotAllowedException('API key does not belong to the owner.');
            }

            if (! $lockedKey->isActive()) {
                throw new ApiKeyRotationNotAllowedException('Only active API keys may be rotated.');
            }

            $credentials = $this->tokenGenerator->generate();
            $replacement = $this->apiKeyRepository->create(new CreateApiKeyData(
                userId: (string) $lockedOwner->getKey(),
                name: $lockedKey->name,
                keyPrefix: $credentials['key_prefix'],
                keyHash: $credentials['key_hash'],
                permissions: $lockedKey->permissions,
                rateLimitPerMinute: $lockedKey->rate_limit_per_minute,
                expiresAt: $lockedKey->expires_at,
                revokedAt: null,
                metadata: $lockedKey->metadata,
            ));

            $lockedKey->revoked_at = now();
            $lockedKey->save();

            $this->audit->write('commercial.api_key_rotated', (string) $lockedOwner->getKey(), $replacement, null, null, [
                'replaced_api_key_id' => (string) $lockedKey->getKey(),
                'replacement_api_key_id' => (string) $replacement->getKey(),
            ]);

            return new ApiKeyIssuanceResult($replacement, $credentials['plain_token']);
        });
    }
}
