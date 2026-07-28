<?php

declare(strict_types=1);

namespace App\Contracts\Billing;

interface ProviderPayloadParser
{
    public function provider(): string;

    public function supports(string $contentType): bool;

    /** @return array<string, mixed> */
    public function parse(string $rawBody, string $contentType): array;
}
