<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Smallest-scope subject types an admin can flag as an outbound launch
 * canary. Each type resolves against an existing row (user, inbox, domain,
 * or API key) — canary rows never reference deleted/unknown subjects.
 */
enum OutboundCanarySubjectType: string
{
    case User = 'user';
    case Inbox = 'inbox';
    case Domain = 'domain';
    case ApiKey = 'api_key';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
