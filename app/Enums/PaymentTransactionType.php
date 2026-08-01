<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTransactionType: string
{
    case Authorization = 'authorization';
    case Capture = 'capture';
    case Sale = 'sale';
    case Refund = 'refund';
    case PartialRefund = 'partial_refund';
    case Chargeback = 'chargeback';
    case ChargebackReversal = 'chargeback_reversal';
    case Void = 'void';
    case Adjustment = 'adjustment';
}
