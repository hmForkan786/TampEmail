<?php

use App\Exceptions\Billing\InvalidMoneyException;
use App\ValueObjects\Money;

it('creates money from minor units', function (): void {
    $money = Money::fromMinor(1999, 'usd');
    expect($money->amountMinor)->toBe(1999)
        ->and($money->currency)->toBe('USD');
});

it('parses strict decimal strings without floats', function (): void {
    $money = Money::fromDecimalString('19.99', 'USD');
    expect($money->amountMinor)->toBe(1999);
});

it('rejects float input at the type level and invalid decimals', function (): void {
    expect(fn () => Money::fromDecimalString('19.999', 'USD'))->toThrow(InvalidMoneyException::class);
    expect(fn () => Money::fromDecimalString('-1', 'USD'))->toThrow(InvalidMoneyException::class);
    expect(fn () => Money::fromDecimalString('abc', 'USD'))->toThrow(InvalidMoneyException::class);
});

it('adds and subtracts with currency guardrails', function (): void {
    $a = Money::fromMinor(500, 'USD');
    $b = Money::fromMinor(499, 'USD');
    expect($a->add($b)->amountMinor)->toBe(999)
        ->and($a->subtract(Money::fromMinor(100, 'USD'))->amountMinor)->toBe(400)
        ->and(fn () => $a->subtract(Money::fromMinor(600, 'USD')))->toThrow(InvalidMoneyException::class)
        ->and(fn () => $a->add(Money::fromMinor(1, 'EUR')))->toThrow(InvalidMoneyException::class);
});

it('compares equality deterministically', function (): void {
    expect(Money::fromMinor(900, 'USD')->equals(Money::fromDecimalString('9.00', 'USD')))->toBeTrue();
});
