<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class InvalidMailServerProvisioningDataException extends InvalidArgumentException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
