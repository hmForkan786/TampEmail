<?php

declare(strict_types=1);

namespace App\DTOs\Outbound;

use App\Enums\OutboundProviderEventType;
use Carbon\CarbonInterface;

/**
 * Sanitized provider delivery event after signature verification and parsing.
 *
 * Never includes raw webhook bodies, secrets, or recipient plaintext beyond policy.
 */
final readonly class OutboundProviderEventData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $provider,
        public string $providerEventId,
        public ?string $providerMessageId,
        public OutboundProviderEventType $eventType,
        public CarbonInterface $providerEventAt,
        public array $metadata = [],
    ) {}
}
