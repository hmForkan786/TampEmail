<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Raised when an API-key permission list contains invalid or unknown scopes.
 */
final class InvalidApiKeyScopeException extends InvalidArgumentException
{
    public function __construct(string $message = 'The API key permissions contain an invalid scope.')
    {
        parent::__construct($message);
    }
}
