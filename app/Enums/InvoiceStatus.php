<?php

declare(strict_types=1);

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Paid = 'paid';
    case Void = 'void';

    public function isImmutableTotals(): bool
    {
        return in_array($this, [self::Issued, self::Paid, self::Void], true);
    }

    public function allowsVoid(): bool
    {
        return in_array($this, [self::Draft, self::Issued], true);
    }
}
