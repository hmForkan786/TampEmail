<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentSettlementStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Settled = 'settled';
    case Failed = 'failed';
    case Reversed = 'reversed';
    case Unknown = 'unknown';
}
