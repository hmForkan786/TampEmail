<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\PaymentSettlementStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;

final readonly class VerifiedProviderEvent
{
    /** @param array<string, mixed> $safeMetadata */
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
        public ?ProviderPaymentStatus $paymentStatus = null,
        public ?string $providerOrderReference = null,
        public ?string $providerSessionId = null,
        public ?string $occurredAt = null,
        public ?PaymentSettlementStatus $settlementStatus = null,
        public ?string $settlementReference = null,
        public ?string $rawPayloadFingerprint = null,
        public bool $signatureVerified = true,
        public array $safeMetadata = [],
    ) {}

    public function normalizedStatus(): ProviderPaymentStatus
    {
        return $this->paymentStatus ?? ($this->succeeded ? ProviderPaymentStatus::Succeeded : ProviderPaymentStatus::Failed);
    }
}
