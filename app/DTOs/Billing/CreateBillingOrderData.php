<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\BillingCycle;
use App\Enums\BillingOrderType;

final readonly class CreateBillingOrderData
{
    public function __construct(
        public string $userId,
        public string $planId,
        public BillingOrderType $type,
        public BillingCycle $billingCycle,
        public string $idempotencyKey,
        public ?string $subscriptionId = null,
        public ?string $provider = null,
    ) {}
}
