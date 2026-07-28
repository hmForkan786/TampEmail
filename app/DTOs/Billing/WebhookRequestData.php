<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

final readonly class WebhookRequestData
{
    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $provider,
        public array $headers,
        public array $payload,
        public string $rawBody,
    ) {}
}
