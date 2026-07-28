<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use JsonSerializable;

final readonly class StripeAccount implements JsonSerializable
{
    /** @param list<string> $webhookSecrets */
    public function __construct(
        public string $key,
        public string $environment,
        public string $secretKey,
        public ?string $publishableKey,
        public array $webhookSecrets,
        public ?string $connectedAccountId,
        public ?string $apiVersion,
    ) {}

    /** @return array{key:string,environment:string,connected_account_id:?string,api_version:?string} */
    public function jsonSerialize(): array
    {
        return ['key' => $this->key, 'environment' => $this->environment, 'connected_account_id' => $this->connectedAccountId, 'api_version' => $this->apiVersion];
    }
}
