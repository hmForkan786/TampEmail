<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a user status change is refused for authorization or policy reasons.
 */
final class UserStatusChangeNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'The user status change is not allowed.')
    {
        parent::__construct($message);
    }
}
