<?php
declare(strict_types=1);
namespace App\Services\Inbound;
use App\Contracts\InboundWebhookDispatcher;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use App\Jobs\ProcessInboundMessageJob;
final class QueuedInboundWebhookDispatcher implements InboundWebhookDispatcher
{
    public function dispatch(ProviderWebhookEnvelope $envelope): void { ProcessInboundMessageJob::dispatch($envelope); }
}
