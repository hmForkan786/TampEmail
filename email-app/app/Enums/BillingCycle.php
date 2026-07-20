<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Billing interval options for subscriptions.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Lifetime = 'lifetime';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Monthly->value => 'Monthly',
            self::Yearly->value => 'Yearly',
            self::Lifetime->value => 'Lifetime',
        ];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
