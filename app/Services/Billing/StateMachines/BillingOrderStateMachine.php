<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\BillingOrderStatus;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;

final class BillingOrderStateMachine
{
    /** @var array<string, list<BillingOrderStatus>> */
    private const TRANSITIONS = [
        BillingOrderStatus::Pending->value => [
            BillingOrderStatus::Processing,
            BillingOrderStatus::Cancelled,
            BillingOrderStatus::Expired,
            BillingOrderStatus::Failed,
        ],
        BillingOrderStatus::Processing->value => [
            BillingOrderStatus::Paid,
            BillingOrderStatus::Failed,
            BillingOrderStatus::Cancelled,
            BillingOrderStatus::Expired,
        ],
        BillingOrderStatus::Paid->value => [
            BillingOrderStatus::Refunded,
            BillingOrderStatus::PartiallyRefunded,
            BillingOrderStatus::ChargedBack,
        ],
        BillingOrderStatus::PartiallyRefunded->value => [
            BillingOrderStatus::PartiallyRefunded,
            BillingOrderStatus::Refunded,
            BillingOrderStatus::ChargedBack,
        ],
    ];

    public function assertCanTransition(BillingOrderStatus $from, BillingOrderStatus $to): void
    {
        if ($from === $to) {
            return;
        }

        $allowed = self::TRANSITIONS[$from->value] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidBillingStateTransitionException(
                "Invalid billing order transition from [{$from->value}] to [{$to->value}].",
            );
        }
    }
}
