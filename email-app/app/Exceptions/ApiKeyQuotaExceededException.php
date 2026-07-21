<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ApiKeyQuotaExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'API key quota exceeded.',
    ) {
        parent::__construct($message);
    }
}
