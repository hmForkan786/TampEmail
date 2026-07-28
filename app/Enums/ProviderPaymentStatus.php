<?php

declare(strict_types=1);

namespace App\Enums;

enum ProviderPaymentStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case ChargedBack = 'charged_back';
    case Reversed = 'reversed';
    case Unknown = 'unknown';

    public function isFinancialSuccess(): bool
    {
        return in_array($this, [self::Authorized, self::Captured, self::Succeeded], true);
    }
}
