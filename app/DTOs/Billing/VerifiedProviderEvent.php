<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\PaymentTransactionType;

final readonly class VerifiedProviderEvent
{
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public string $eventType,
        public string $providerTransactionId,
        public string $billingOrderId,
        public int $amountMinor,
        public string $currency,
        public PaymentTransactionType $transactionType,
        public bool $succeeded,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}
}
