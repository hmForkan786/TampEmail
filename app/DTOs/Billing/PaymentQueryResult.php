<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class PaymentQueryResult
{
    public function __construct(
        public string $providerTransactionId,
        public string $billingOrderId,
        public int $amountMinor,
        public string $currency,
        public bool $succeeded,
    ) {}
}
