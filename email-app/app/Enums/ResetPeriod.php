<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Usage counter reset intervals for metered subscription features.
 */
enum ResetPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Daily->value => 'Daily',
            self::Weekly->value => 'Weekly',
            self::Monthly->value => 'Monthly',
            self::Yearly->value => 'Yearly',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
