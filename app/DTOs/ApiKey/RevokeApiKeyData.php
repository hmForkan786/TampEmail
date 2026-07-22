<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

/**
 * Input for the canonical API key revocation action.
 *
 * Actor capability must be resolved from the locked actor row and ApiKeyPolicy,
 * never inferred from this payload alone.
 */
final readonly class RevokeApiKeyData
{
    public function __construct(
        public string $actorUserId,
        public string $apiKeyId,
        public string $source = 'filament',
    ) {}
}
