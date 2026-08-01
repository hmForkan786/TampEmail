<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationPreferenceChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
