<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class CheckoutSessionResult
{
    public function __construct(
        public string $provider,
        public string $providerReference,
        public string $checkoutUrl,
        public ?string $expiresAt = null,
    ) {}
}
