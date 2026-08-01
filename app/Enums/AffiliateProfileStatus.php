<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateProfileStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Rejected = 'rejected';
    case Closed = 'closed';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Pending->value => 'Pending',
            self::Active->value => 'Active',
            self::Suspended->value => 'Suspended',
            self::Rejected->value => 'Rejected',
            self::Closed->value => 'Closed',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
