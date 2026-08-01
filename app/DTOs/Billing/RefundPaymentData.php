<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class RefundPaymentData
{
    public function __construct(
        public string $provider,
        public string $billingOrderId,
        public string $providerTransactionId,
        public int $amountMinor,
        public string $currency,
        public string $idempotencyKey,
        public bool $partial = false,
    ) {}
}
