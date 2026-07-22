<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when the target user for a platform role change is missing or unavailable.
 */
final class PlatformRoleTargetUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'The platform role change target is unavailable.')
    {
        parent::__construct($message);
    }
}
