<?php

declare(strict_types=1);

namespace App\Services\Inbound;

use App\Contracts\InboundWebhookDispatcher;
use App\DTOs\Inbound\ProviderWebhookEnvelope;

final class NullInboundWebhookDispatcher implements InboundWebhookDispatcher
{
    public function dispatch(ProviderWebhookEnvelope $envelope): void {}
}
