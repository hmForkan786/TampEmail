<?php

declare(strict_types=1);

namespace App\Enums;

enum AnalyticsReportPeriod: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Custom = 'custom';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Daily->value => 'Daily',
            self::Weekly->value => 'Weekly',
            self::Monthly->value => 'Monthly',
            self::Custom->value => 'Custom',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
