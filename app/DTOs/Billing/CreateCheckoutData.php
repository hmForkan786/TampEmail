<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class CreateCheckoutData
{
    public function __construct(
        public string $billingOrderId,
        public string $userId,
        public string $provider,
        public int $amountMinor,
        public string $currency,
        public string $successUrl,
        public string $cancelUrl,
        public string $idempotencyKey,
    ) {}
}
