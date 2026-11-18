<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class ProviderWebhookVerificationContext
{
    /** @param list<array{id:string, algorithm:string, secret:string}> $activeSigningKeys */
    public function __construct(
        public string $provider,
        public int $allowedClockSkewSeconds,
        public int $replayWindowSeconds,
        public array $activeSigningKeys,
        public ?string $expectedAudience,
        public string $expectedEnvironment,
        /** @var list<string> */
        public array $requiredHeaders,
        public string $configurationVersion,
    ) {}
}
