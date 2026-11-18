<?php

declare(strict_types=1);

namespace App\Enums;

enum AdAudience: string
{
    case All = 'all';
    case FreeOnly = 'free_only';
    case PremiumExcluded = 'premium_excluded';
    case AnonymousOnly = 'anonymous_only';
    case LoggedInOnly = 'logged_in_only';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::All->value => 'Everyone',
            self::FreeOnly->value => 'Free plan only',
            self::PremiumExcluded->value => 'Premium excluded',
            self::AnonymousOnly->value => 'Anonymous only',
            self::LoggedInOnly->value => 'Logged in only',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
