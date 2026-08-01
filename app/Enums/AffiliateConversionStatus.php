<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateConversionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Reversed = 'reversed';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Pending->value => 'Pending',
            self::Approved->value => 'Approved',
            self::Rejected->value => 'Rejected',
            self::Reversed->value => 'Reversed',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
