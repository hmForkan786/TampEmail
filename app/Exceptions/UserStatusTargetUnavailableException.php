<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the target user for a status change is missing or unavailable.
 */
final class UserStatusTargetUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'The user status change target is unavailable.')
    {
        parent::__construct($message);
    }
}
