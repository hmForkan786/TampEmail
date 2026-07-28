<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\BillingCycle;

final readonly class ActivatePaidSubscriptionData
{
    public function __construct(
        public string $billingOrderId,
        public string $userId,
        public string $planId,
        public BillingCycle $billingCycle,
        public ?string $subscriptionId = null,
    ) {}
}
