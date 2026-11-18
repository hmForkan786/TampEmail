<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use JsonSerializable;

final readonly class SslCommerzStore implements JsonSerializable
{
    public function __construct(
        public string $key,
        public string $storeId,
        public string $storePassword,
        public string $environment,
        public string $baseUrl,
    ) {}

    /** @return array{key:string, environment:string, base_url:string} */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'environment' => $this->environment, 'base_url' => $this->baseUrl];
    }
}
