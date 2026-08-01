<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationPreferenceCategory: string
{
    case Security = 'security';
    case Billing = 'billing';
    case Inbox = 'inbox';
    case Outbound = 'outbound';
    case Affiliate = 'affiliate';
    case ProductUpdates = 'product_updates';
    case Marketing = 'marketing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isCritical(): bool
    {
        return (bool) config('settings.notifications.categories.'.$this->value.'.critical', false);
    }

    public function isTransactionalBilling(): bool
    {
        return (bool) config('settings.notifications.categories.'.$this->value.'.transactional', false);
    }

    public function isMarketing(): bool
    {
        return (bool) config('settings.notifications.categories.'.$this->value.'.marketing', false);
    }
}
