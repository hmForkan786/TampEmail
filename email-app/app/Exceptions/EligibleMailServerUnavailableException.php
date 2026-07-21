<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class EligibleMailServerUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'No eligible mail server is available.',
    ) {
        parent::__construct($message);
    }
}
