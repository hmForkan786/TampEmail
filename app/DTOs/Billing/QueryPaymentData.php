<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class QueryPaymentData
{
    public function __construct(
        public string $provider,
        public string $providerTransactionId,
        public string $billingOrderId,
    ) {}
}
