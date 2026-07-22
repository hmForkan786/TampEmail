<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a platform role change is refused for authorization or policy reasons.
 */
final class PlatformRoleChangeNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'The platform role change is not allowed.')
    {
        parent::__construct($message);
    }
}
