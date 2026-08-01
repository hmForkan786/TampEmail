<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\Billing\UnknownPaymentProviderException;

/** Known provider slugs; registry accepts any configured lowercase slug. */
enum PaymentProviderName: string
{
    case Fake = 'fake';
    case Stripe = 'stripe';
    case SslCommerz = 'sslcommerz';
    case ManualCrypto = 'manual_crypto';
    case Bkash = 'bkash';
    case Nagad = 'nagad';

    public static function normalize(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        if ($normalized === '' || ! preg_match('/^[a-z][a-z0-9_\-]{0,49}$/', $normalized)) {
            throw new UnknownPaymentProviderException('Invalid payment provider identifier.');
        }

        return $normalized;
    }

    public static function parse(string $provider): self
    {
        $normalized = self::normalize($provider);

        return self::tryFrom($normalized) ?? throw new UnknownPaymentProviderException("Unknown payment provider [{$normalized}].");
    }
}
