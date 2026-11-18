<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateCommissionPlanStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Active->value => 'Active',
            self::Inactive->value => 'Inactive',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
