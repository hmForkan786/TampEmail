<?php

declare(strict_types=1);

namespace App\DTOs\Inbound;

use InvalidArgumentException;

final readonly class RecipientAddress
{
    public function __construct(public string $localPart, public string $domain)
    {
        if ($localPart === '' || $domain === '') throw new InvalidArgumentException('Invalid recipient address.');
    }

    public function fullAddress(): string
    {
        return $this->localPart.'@'.$this->domain;
    }
}
