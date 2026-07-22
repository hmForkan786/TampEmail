<?php

declare(strict_types=1);

namespace App\DTOs\Inbound;

final readonly class RecipientInput
{
    public function __construct(public string $rawRecipient, public bool $publicIngress = true) {}
}
