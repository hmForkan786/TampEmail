<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment provider identifiers for subscription billing.
 */
enum PaymentGateway: string
{
    case Stripe = 'stripe';
    case Paddle = 'paddle';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Stripe->value => 'Stripe',
            self::Paddle->value => 'Paddle',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
