<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;

/**
 * Fail-closed transport used when outbound sending is disabled or misconfigured.
 *
 * Never silently falls back to a local/default mailer.
 */
final class UnavailableOutboundTransport implements OutboundTransportInterface
{
    public function __construct(
        private readonly string $failureCode = 'transport_unavailable',
        private readonly string $failureMessage = 'Outbound transport is not configured.',
        private readonly bool $configurationFailure = false,
    ) {}

    public function send(OutboundMessageData $message): OutboundDeliveryResult
    {
        if ($this->configurationFailure || $this->failureCode !== 'transport_unavailable') {
            return OutboundDeliveryResult::configurationFailure(
                failureCode: $this->failureCode,
                failureMessage: $this->failureMessage,
                provider: 'unavailable',
            );
        }

        return OutboundDeliveryResult::permanentFailure(
            failureCode: $this->failureCode,
            failureMessage: $this->failureMessage,
            provider: 'unavailable',
        );
    }
}
