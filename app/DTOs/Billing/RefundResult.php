<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class RefundResult
{
    public function __construct(
        public string $providerRefundId,
        public int $amountMinor,
        public string $currency,
        public bool $succeeded,
    ) {}
}
