<?php

declare(strict_types=1);

namespace App\DTOs\ApiKey;

use App\Models\ApiKey;

/** Creation-only result containing the plaintext token exactly once. */
final readonly class ApiKeyIssuanceResult
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainToken,
    ) {}
}
