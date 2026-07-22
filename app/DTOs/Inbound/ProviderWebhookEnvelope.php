<?php

declare(strict_types=1);

namespace App\DTOs\Inbound;

use Carbon\CarbonInterface;

final readonly class ProviderWebhookEnvelope
{
    public function __construct(
        public string $provider,
        public string $providerMessageId,
        public string $recipient,
        public ?string $sender,
        public CarbonInterface $receivedAt,
        public string $rawMimePayload,
        public int $contentLength,
    ) {}
}
