<?php

declare(strict_types=1);

namespace App\Enums;

enum AccountRecoveryReasonCode: string
{
    case LostEmailAccess = 'lost_email_access';
    case SuspectedCompromise = 'suspected_compromise';
    case EmailChangeNeeded = 'email_change_needed';
    case Other = 'other';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::LostEmailAccess->value => 'Lost email access',
            self::SuspectedCompromise->value => 'Suspected compromise',
            self::EmailChangeNeeded->value => 'Email change needed',
            self::Other->value => 'Other',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
