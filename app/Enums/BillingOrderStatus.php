<?php

declare(strict_types=1);

namespace App\Enums;

enum BillingOrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case ChargedBack = 'charged_back';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Expired, self::Refunded, self::ChargedBack => true,
            default => false,
        };
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
