<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use DateTimeImmutable;

final readonly class WebhookVerificationResult
{
    /**
     * @param array<string, scalar|null> $safeFailureContext
     * @param array<string, scalar|null> $verificationMetadata
     */
    public function __construct(
        public bool $verified,
        public string $provider,
        public ?string $signatureVersion,
        public ?string $matchedKeyId,
        public ?DateTimeImmutable $signedAt,
        public ?string $nonce,
        public ?string $providerEventId,
        public string $payloadHash,
        public ?string $canonicalPayloadHash,
        public ?string $failureCode,
        public array $safeFailureContext = [],
        public array $verificationMetadata = [],
    ) {}
}
