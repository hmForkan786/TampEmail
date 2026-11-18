<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateRegistrationMode: string
{
    case Disabled = 'disabled';
    case ManualApproval = 'manual_approval';
    case Automatic = 'automatic';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Disabled->value => 'Disabled',
            self::ManualApproval->value => 'Manual approval',
            self::Automatic->value => 'Automatic',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
