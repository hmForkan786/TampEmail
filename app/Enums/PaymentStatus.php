<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment transaction outcome states.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Pending->value => 'Pending',
            self::Paid->value => 'Paid',
            self::Failed->value => 'Failed',
            self::Refunded->value => 'Refunded',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
