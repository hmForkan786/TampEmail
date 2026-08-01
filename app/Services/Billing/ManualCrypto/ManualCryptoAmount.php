<?php

declare(strict_types=1);

namespace App\Services\Billing\ManualCrypto;

use InvalidArgumentException;

final class ManualCryptoAmount
{
    public static function toUnits(string $amount): int
    {
        $value = trim($amount);
        if (! preg_match('/^(?:0|[1-9]\d*)(?:\.(\d{1,6}))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Amount must be a positive USDT value with at most six decimals.');
        }
        $whole = (int) strtok($value, '.');
        $fraction = str_pad((string) ($matches[1] ?? ''), 6, '0');
        $units = ($whole * 1_000_000) + (int) $fraction;
        if ($units <= 0) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        return $units;
    }

    public static function format(int $units): string
    {
        return sprintf('%d.%06d', intdiv($units, 1_000_000), $units % 1_000_000);
    }

    public static function expectedUnits(int $minor): int
    {
        return $minor * 10_000;
    }
}
