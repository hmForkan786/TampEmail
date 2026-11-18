<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateCommissionEntryStatus: string
{
    case Pending = 'pending';
    case Available = 'available';
    case Held = 'held';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Pending->value => 'Pending',
            self::Available->value => 'Available',
            self::Held->value => 'Held',
            self::Paid->value => 'Paid',
            self::Cancelled->value => 'Cancelled',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
