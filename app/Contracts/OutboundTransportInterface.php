<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Outbound\OutboundDeliveryResult;
use App\DTOs\Outbound\OutboundMessageData;

/**
 * Provider-independent outbound mail transport.
 */
interface OutboundTransportInterface
{
    public function send(OutboundMessageData $message): OutboundDeliveryResult;
}
