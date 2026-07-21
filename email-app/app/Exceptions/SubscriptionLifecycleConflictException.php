<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class SubscriptionLifecycleConflictException extends RuntimeException
{
    public function __construct(
        string $message = 'Subscription lifecycle conflict.',
    ) {
        parent::__construct($message);
    }
}
