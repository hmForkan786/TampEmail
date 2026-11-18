<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\BillingCycle;

final readonly class StartCheckoutData
{
    /** @param array<string, scalar|null> $metadata */
    public function __construct(
        public string $userId,
        public string $planId,
        public string $gateway,
        public BillingCycle $billingCycle,
        public string $idempotencyKey,
        public string $successUrl,
        public string $cancelUrl,
        public ?string $returnUrl = null,
        public ?string $clientReference = null,
        public array $metadata = [],
    ) {}
}
