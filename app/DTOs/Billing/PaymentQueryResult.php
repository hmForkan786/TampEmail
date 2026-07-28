<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\ProviderPaymentStatus;

final readonly class PaymentQueryResult
{
    public function __construct(
        public string $providerTransactionId,
        public string $billingOrderId,
        public int $amountMinor,
        public string $currency,
        public bool $succeeded,
        public ?ProviderPaymentStatus $status = null,
        public ?string $providerEventId = null,
        public ?string $settlementStatus = null,
    ) {}
}
