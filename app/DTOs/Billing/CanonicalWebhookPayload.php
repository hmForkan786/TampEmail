<?php

declare(strict_types=1);

namespace App\DTOs\Billing;

use App\Enums\WebhookCanonicalizationStrategy;
use JsonSerializable;

final readonly class CanonicalWebhookPayload implements JsonSerializable
{
    public function __construct(public string $bytes, public WebhookCanonicalizationStrategy $strategy, public string $hash) {}

    /** @return array{strategy:string, hash:string} */
    public function jsonSerialize(): array
    {
        return ['strategy' => $this->strategy->value, 'hash' => $this->hash];
    }
}
