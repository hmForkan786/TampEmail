<?php

declare(strict_types=1);

namespace App\ValueObjects;

use App\Exceptions\Billing\InvalidMoneyException;

/** Immutable money in minor units; never uses floating point. */
final readonly class Money
{
    private function __construct(
        public int $amountMinor,
        public string $currency,
    ) {
        if ($this->amountMinor < 0) {
            throw new InvalidMoneyException('Money amount cannot be negative.');
        }
    }

    public static function fromMinor(int $amountMinor, string $currency): self
    {
        return new self($amountMinor, self::normalizeCurrency($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, self::normalizeCurrency($currency));
    }

    /** Strict decimal string parsing; floats are rejected at the type level. */
    public static function fromDecimalString(string $amount, string $currency): self
    {
        $currency = self::normalizeCurrency($currency);
        $amount = trim($amount);

        if ($amount === '' || ! preg_match('/^\d+(?:\.\d+)?$/', $amount)) {
            throw new InvalidMoneyException('Invalid decimal amount format.');
        }

        $places = self::decimalPlaces($currency);
        $parts = explode('.', $amount, 2);
        $major = (int) $parts[0];
        $fraction = $parts[1] ?? '';

        if (strlen($fraction) > $places) {
            throw new InvalidMoneyException('Too many decimal places for currency.');
        }

        $fraction = str_pad($fraction, $places, '0');
        $multiplier = 10 ** $places;
        $minor = ($major * $multiplier) + (int) $fraction;

        return new self($minor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        if ($other->amountMinor > $this->amountMinor) {
            throw new InvalidMoneyException('Subtraction would produce a negative amount.');
        }

        return new self($this->amountMinor - $other->amountMinor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function isZero(): bool
    {
        return $this->amountMinor === 0;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    /** @return array{amount_minor: int, currency: string} */
    public function toArray(): array
    {
        return [
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
        ];
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidMoneyException('Currency mismatch.');
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $normalized = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $normalized)) {
            throw new InvalidMoneyException('Currency must be a three-letter ISO code.');
        }

        return $normalized;
    }

    private static function decimalPlaces(string $currency): int
    {
        return match ($currency) {
            'JPY', 'KRW', 'VND' => 0,
            default => 2,
        };
    }
}
