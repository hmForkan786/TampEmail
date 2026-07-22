<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the target API key for revocation is missing or unavailable.
 */
final class ApiKeyRevocationTargetUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'The API key revocation target is unavailable.')
    {
        parent::__construct($message);
    }
}
