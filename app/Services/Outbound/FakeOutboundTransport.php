<?php

declare(strict_types=1);

namespace App\Services\Outbound;

use App\Contracts\OutboundTransportInterface;
use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;

/**
 * In-memory fake transport for application tests.
 */
final class FakeOutboundTransport implements OutboundTransportInterface
{
    /** @var list<OutboundMessageData> */
    public array $sent = [];

    public function __construct(
        private OutboundDeliveryResult $nextResult,
    ) {}

    public function setNextResult(OutboundDeliveryResult $result): void
    {
        $this->nextResult = $result;
    }

    public function send(OutboundMessageData $message): OutboundDeliveryResult
    {
        $this->sent[] = $message;

        return $this->nextResult;
    }
}
