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
    case Closed = 'closed';

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
            self::Closed->value => 'Closed',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Statuses that may retain an authenticated web session (product gates apply separately).
     */
    public function mayAuthenticate(): bool
    {
        return $this === self::Active || $this === self::Pending;
    }

    /**
     * Statuses that block all access (login, verification into active, API).
     */
    public function isBlocked(): bool
    {
        return in_array($this, [self::Suspended, self::Banned, self::Closed], true);
    }
}
