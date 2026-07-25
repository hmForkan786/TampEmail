<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Outbound\OutboundProviderEventData;
use App\Services\Outbound\OutboundProviderEventProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessOutboundProviderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly OutboundProviderEventData $event,
        public readonly string $signatureState = 'verified',
    ) {
        $this->onQueue((string) config('queue.workloads.outbound_events', 'outbound-events'));
    }

    public function handle(OutboundProviderEventProcessor $processor): void
    {
        $processor->ingest($this->event, $this->signatureState);
    }
}
