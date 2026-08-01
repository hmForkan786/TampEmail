<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateAttributionModel: string
{
    case FirstClick = 'first_click';
    case LastClick = 'last_click';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::FirstClick->value => 'First click',
            self::LastClick->value => 'Last click',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
