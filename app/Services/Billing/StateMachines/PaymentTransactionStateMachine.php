<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;

final class PaymentTransactionStateMachine
{
    /** @var array<string, list<PaymentTransactionStatus>> */
    private const TRANSITIONS = [
        PaymentTransactionStatus::Pending->value => [
            PaymentTransactionStatus::Succeeded,
            PaymentTransactionStatus::Failed,
            PaymentTransactionStatus::Cancelled,
        ],
        PaymentTransactionStatus::Succeeded->value => [
            PaymentTransactionStatus::Reversed,
        ],
    ];

    public function assertCanTransition(PaymentTransactionStatus $from, PaymentTransactionStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidBillingStateTransitionException(
                "Invalid payment transaction transition from [{$from->value}] to [{$to->value}].",
            );
        }
    }
}
