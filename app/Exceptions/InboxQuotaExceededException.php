<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class InboxQuotaExceededException extends RuntimeException
{
    public function __construct(
        string $message = 'Inbox quota exceeded.',
    ) {
        parent::__construct($message);
    }
}
