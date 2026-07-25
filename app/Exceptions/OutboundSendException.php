<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class OutboundSendException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
