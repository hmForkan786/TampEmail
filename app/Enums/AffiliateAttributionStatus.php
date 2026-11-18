<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateAttributionStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Expired = 'expired';
    case Invalidated = 'invalidated';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Active->value => 'Active',
            self::Converted->value => 'Converted',
            self::Expired->value => 'Expired',
            self::Invalidated->value => 'Invalidated',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
