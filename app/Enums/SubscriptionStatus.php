<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Subscription lifecycle states for billing entitlements.
 */
enum SubscriptionStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case RenewalDue = 'renewal_due';
    case Grace = 'grace';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Trial->value => 'Trial',
            self::Active->value => 'Active',
            self::RenewalDue->value => 'Renewal Due',
            self::Grace->value => 'Grace Period',
            self::Cancelled->value => 'Cancelled',
            self::Expired->value => 'Expired',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
