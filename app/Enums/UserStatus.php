<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Account lifecycle states for registered users.
 */
enum UserStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Suspended = 'suspended';
    case Banned = 'banned';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Active->value => 'Active',
            self::Pending->value => 'Pending',
            self::Suspended->value => 'Suspended',
            self::Banned->value => 'Banned',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
