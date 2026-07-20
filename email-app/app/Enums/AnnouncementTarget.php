<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Audience scopes for global announcements.
 */
enum AnnouncementTarget: string
{
    case All = 'all';
    case Guest = 'guest';
    case Authenticated = 'authenticated';
    case Premium = 'premium';
    case Admin = 'admin';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::All->value => 'All Users',
            self::Guest->value => 'Guests',
            self::Authenticated->value => 'Authenticated Users',
            self::Premium->value => 'Premium Users',
            self::Admin->value => 'Administrators',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
