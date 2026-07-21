<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class FutureDatedSubscriptionNotAllowedException extends RuntimeException
{
    public function __construct(
        string $message = 'Future-dated subscriptions are not allowed.',
    ) {
        parent::__construct($message);
    }
}
