<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class ManualCryptoWallet
{
    public function __construct(
        public string $id,
        public string $network,
        public string $address,
        public int $priority,
        public string $rotationGroup,
    ) {}
}
