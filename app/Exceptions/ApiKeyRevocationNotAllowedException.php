<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when an API key revocation is refused for authorization or policy reasons.
 */
final class ApiKeyRevocationNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'The API key revocation is not allowed.')
    {
        parent::__construct($message);
    }
}
