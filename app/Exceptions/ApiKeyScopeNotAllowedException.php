<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a known API-key scope is not allowed for the key owner.
 */
final class ApiKeyScopeNotAllowedException extends RuntimeException
{
    public function __construct(string $message = 'The API key owner is not allowed to hold one or more requested scopes.')
    {
        parent::__construct($message);
    }
}
