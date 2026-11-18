<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\BillingOrderType;

final readonly class CheckoutEligibilityResult
{
    public function __construct(
        public bool $eligible,
        public ?BillingOrderType $orderType,
        public ?string $reasonCode,
        public ?string $currentPlanId,
        public string $targetPlanId,
        public ?string $existingOrderId = null,
        public ?string $recommendedAction = null,
        public ?string $subscriptionId = null,
    ) {}
}
