<?php

declare(strict_types=1);

namespace App\Enums;

enum AffiliatePayoutMethod: string
{
    case BankTransfer = 'bank_transfer';
    case PayPal = 'paypal';
    case Wise = 'wise';
    case CryptoUsdtTrc20 = 'crypto_usdt_trc20';
    case ManualOther = 'manual_other';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::BankTransfer->value => 'Bank transfer',
            self::PayPal->value => 'PayPal',
            self::Wise->value => 'Wise',
            self::CryptoUsdtTrc20->value => 'Crypto (USDT TRC20)',
            self::ManualOther->value => 'Manual / other',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
