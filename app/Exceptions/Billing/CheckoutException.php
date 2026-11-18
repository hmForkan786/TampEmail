<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

final class CheckoutException extends BillingException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
