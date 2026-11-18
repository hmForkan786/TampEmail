<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\PaymentSettlementStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;

final class PaymentSettlementStateMachine
{
    public function assertCanTransition(PaymentSettlementStatus $from, PaymentSettlementStatus $to): void
    {
        $allowed = match ($from) {
            PaymentSettlementStatus::Pending => [PaymentSettlementStatus::Processing, PaymentSettlementStatus::Settled, PaymentSettlementStatus::Failed],
            PaymentSettlementStatus::Processing => [PaymentSettlementStatus::Settled, PaymentSettlementStatus::Failed],
            PaymentSettlementStatus::Settled => [PaymentSettlementStatus::Reversed],
            PaymentSettlementStatus::Failed => [PaymentSettlementStatus::Processing],
            PaymentSettlementStatus::Unknown => [PaymentSettlementStatus::Pending, PaymentSettlementStatus::Settled],
            default => [],
        };
        if ($from !== $to && ! in_array($to, $allowed, true)) {
            throw new InvalidBillingStateTransitionException("Invalid settlement transition from [{$from->value}] to [{$to->value}].");
        }
    }
}
