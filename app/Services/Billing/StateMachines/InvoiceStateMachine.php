<?php

declare(strict_types=1);

namespace App\Services\Billing\StateMachines;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceException;

final class InvoiceStateMachine
{
    public function assertCanTransition(InvoiceStatus $from, InvoiceStatus $to): void
    {
        $allowed = match ($from) {
            InvoiceStatus::Draft => [InvoiceStatus::Issued, InvoiceStatus::Void],
            InvoiceStatus::Issued => [InvoiceStatus::Paid, InvoiceStatus::Void],
            InvoiceStatus::Paid, InvoiceStatus::Void => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw InvoiceException::invalidTransition(sprintf(
                'Invoice cannot transition from %s to %s.',
                $from->value,
                $to->value,
            ));
        }
    }
}
