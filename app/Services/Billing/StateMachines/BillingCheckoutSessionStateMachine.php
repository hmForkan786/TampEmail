<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\BillingCheckoutSessionStatus;
use App\Exceptions\Billing\CheckoutException;

final class BillingCheckoutSessionStateMachine
{
    public function assertCanTransition(BillingCheckoutSessionStatus $from, BillingCheckoutSessionStatus $to): void
    {
        $allowed = match ($from) {
            BillingCheckoutSessionStatus::Created => [BillingCheckoutSessionStatus::Pending, BillingCheckoutSessionStatus::Failed],
            BillingCheckoutSessionStatus::Pending => [BillingCheckoutSessionStatus::Redirected, BillingCheckoutSessionStatus::Failed, BillingCheckoutSessionStatus::Cancelled, BillingCheckoutSessionStatus::Expired],
            BillingCheckoutSessionStatus::Redirected => [BillingCheckoutSessionStatus::Completed, BillingCheckoutSessionStatus::Cancelled, BillingCheckoutSessionStatus::Expired],
            BillingCheckoutSessionStatus::Failed => [BillingCheckoutSessionStatus::Pending],
            default => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw new CheckoutException('invalid_checkout_transition', 'The checkout state transition is invalid.');
        }
    }
}
