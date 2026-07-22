<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ApiKeyOwnerRequiredException extends RuntimeException
{
    public function __construct(string $message = 'A valid user owner is required for an API key.')
    {
        parent::__construct($message);
    }
}
