<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class ProviderCallbackAcknowledgement
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public int $httpStatus,
        public array $body,
        public string $contentType,
        public bool $accepted,
        public bool $retryRecommended,
    ) {}
}
