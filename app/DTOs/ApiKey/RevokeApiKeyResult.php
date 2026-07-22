<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

use App\Models\ApiKey;
use Carbon\CarbonInterface;

/**
 * Outcome of an API key revocation attempt.
 */
final readonly class RevokeApiKeyResult
{
    public function __construct(
        public ApiKey $apiKey,
        public bool $changed,
        public CarbonInterface $revokedAt,
        public ?CarbonInterface $previousRevokedAt,
    ) {}
}
