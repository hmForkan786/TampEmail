<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\PaymentProviderEventStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;

final class PaymentProviderEventStateMachine
{
    /** @var array<string, list<PaymentProviderEventStatus>> */
    private const TRANSITIONS = [
        PaymentProviderEventStatus::Received->value => [
            PaymentProviderEventStatus::Processing,
            PaymentProviderEventStatus::Ignored,
        ],
        PaymentProviderEventStatus::Processing->value => [
            PaymentProviderEventStatus::Processed,
            PaymentProviderEventStatus::Failed,
            PaymentProviderEventStatus::Ignored,
        ],
        PaymentProviderEventStatus::Failed->value => [
            PaymentProviderEventStatus::Processing,
        ],
    ];

    public function assertCanTransition(PaymentProviderEventStatus $from, PaymentProviderEventStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidBillingStateTransitionException(
                "Invalid provider event transition from [{$from->value}] to [{$to->value}].",
            );
        }
    }
}
