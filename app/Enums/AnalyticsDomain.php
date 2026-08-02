<?php

declare(strict_types=1);

namespace App\Enums;

enum AnalyticsDomain: string
{
    case Users = 'users';
    case Inbox = 'inbox';
    case Email = 'email';
    case Billing = 'billing';
    case Affiliate = 'affiliate';
    case Ads = 'ads';
    case Api = 'api';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Users->value => 'Users',
            self::Inbox->value => 'Inbox',
            self::Email->value => 'Email',
            self::Billing->value => 'Billing',
            self::Affiliate->value => 'Affiliate',
            self::Ads->value => 'Ads',
            self::Api->value => 'API',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
