<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;

final readonly class RecordPaymentTransactionData
{
    public function __construct(
        public string $billingOrderId,
        public string $userId,
        public string $provider,
        public PaymentTransactionType $type,
        public PaymentTransactionStatus $status,
        public int $amountMinor,
        public string $currency,
        public string $providerTransactionId,
        public string $idempotencyKey,
        public ?string $providerEventId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?\DateTimeInterface $processedAt = null,
        public ?string $payloadFingerprint = null,
        /** @var array<string, mixed>|null */
        public ?array $metadata = null,
    ) {}
}
