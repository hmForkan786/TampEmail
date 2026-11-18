<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliateCommissionEntryType: string
{
    case Commission = 'commission';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case WithdrawalHold = 'withdrawal_hold';
    case WithdrawalRelease = 'withdrawal_release';
    case Payout = 'payout';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Commission->value => 'Commission',
            self::Reversal->value => 'Reversal',
            self::Adjustment->value => 'Adjustment',
            self::WithdrawalHold->value => 'Withdrawal hold',
            self::WithdrawalRelease->value => 'Withdrawal release',
            self::Payout->value => 'Payout',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
