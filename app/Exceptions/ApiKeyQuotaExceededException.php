<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ApiKeyQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly ?string $userId = null,
        public readonly ?int $limit = null,
        public readonly ?int $used = null,
        string $message = 'API key quota exceeded.',
    ) {
        parent::__construct($message);
    }
}
