<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Inbound\ProviderWebhookEnvelope;

interface InboundWebhookDispatcher
{
    public function dispatch(ProviderWebhookEnvelope $envelope): void;
}
