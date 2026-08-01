<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateCommissionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Percentage->value => 'Percentage',
            self::Fixed->value => 'Fixed amount',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
