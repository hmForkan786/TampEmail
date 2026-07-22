<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\DTOs\ApiKey\RevokeApiKeyData;
use App\DTOs\ApiKey\RevokeApiKeyResult;
use App\Exceptions\ApiKeyRevocationNotAllowedException;
use App\Exceptions\ApiKeyRevocationTargetUnavailableException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Canonical application path for revoking a single API key with an audit trail.
 *
 * Lock order inside the transaction:
 * 1. lock actor user row
 * 2. lock target API key row
 * 3. re-check the actor is an active platform admin (ApiKeyPolicy view)
 * 4. no-op when already revoked
 * 5. set revoked_at via explicit attribute + save()
 * 6. append an immutable AuditLog for successful mutations
 */
final class RevokeApiKeyAction
{
    public const AUDIT_ACTION = 'api_key.revoked';

    public function __construct(
        private readonly AuditLogWriter $auditLogWriter,
    ) {}

    /**
     * @throws ApiKeyRevocationNotAllowedException
     * @throws ApiKeyRevocationTargetUnavailableException
     */
    public function execute(RevokeApiKeyData $data): RevokeApiKeyResult
    {
        return DB::transaction(function () use ($data): RevokeApiKeyResult {
            [$actor, $apiKey] = $this->lockActorAndApiKey($data);

            if (! $actor->isPlatformAdmin() || ! Gate::forUser($actor)->allows('view', $apiKey)) {
                throw new ApiKeyRevocationNotAllowedException(
                    'Only an active platform admin may revoke an API key.'
                );
            }

            $revokedAt = now();
            $previousRevokedAt = $apiKey->revoked_at;

            if (! $apiKey->isActive()) {
                return new RevokeApiKeyResult(
                    apiKey: $apiKey->fresh() ?? $apiKey,
                    changed: false,
                    revokedAt: $previousRevokedAt ?? $revokedAt,
                    previousRevokedAt: $previousRevokedAt,
                );
            }

            $apiKey->revoked_at = $revokedAt;
            $apiKey->save();

            $this->auditLogWriter->write(
                action: self::AUDIT_ACTION,
                actorUserId: (string) $actor->getKey(),
                auditable: $apiKey,
                oldValues: [
                    'revoked_at' => null,
                ],
                newValues: [
                    'revoked_at' => $revokedAt->toIso8601String(),
                ],
                metadata: [
                    'target_api_key_id' => (string) $apiKey->getKey(),
                    'owner_user_id' => (string) $apiKey->user_id,
                    'source' => $data->source,
                ],
                occurredAt: $revokedAt,
            );

            return new RevokeApiKeyResult(
                apiKey: $apiKey->fresh() ?? $apiKey,
                changed: true,
                revokedAt: $revokedAt,
                previousRevokedAt: null,
            );
        });
    }

    /**
     * @return array{0: User, 1: ApiKey}
     */
    private function lockActorAndApiKey(RevokeApiKeyData $data): array
    {
        if ($data->actorUserId === '' || $data->apiKeyId === '') {
            throw new ApiKeyRevocationNotAllowedException(
                'A valid actor and API key are required for revocation.'
            );
        }

        $actor = User::query()
            ->whereKey($data->actorUserId)
            ->lockForUpdate()
            ->first();

        if (! $actor instanceof User) {
            throw new ApiKeyRevocationNotAllowedException(
                'The API key revocation actor is unavailable.'
            );
        }

        $apiKey = ApiKey::query()
            ->whereKey($data->apiKeyId)
            ->lockForUpdate()
            ->first();

        if (! $apiKey instanceof ApiKey) {
            throw new ApiKeyRevocationTargetUnavailableException;
        }

        return [$actor, $apiKey];
    }
}
