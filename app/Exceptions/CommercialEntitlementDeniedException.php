<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Raised when a commercial entitlement denies a user-owned mutation. */
final class CommercialEntitlementDeniedException extends RuntimeException
{
    public function __construct(
        public readonly string $feature,
        public readonly ?int $currentValue = null,
        public readonly ?int $allowedLimit = null,
        string $message = 'Your current plan does not include this feature.',
    ) {
        parent::__construct($message);
    }
}
