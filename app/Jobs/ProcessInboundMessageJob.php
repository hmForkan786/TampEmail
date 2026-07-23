<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Actions\Inbound\IngestInboundEmailAction;
use App\DTOs\Inbound\ProviderWebhookEnvelope;
use App\DTOs\Inbound\RecipientInput;
use App\Services\Inbound\InboundMimeParser;
use App\Services\Inbound\InboundRecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Inbound\InboundMetricsRecorder;
use App\Enums\ProcessingStage;
use App\Enums\ProcessingLogStatus;

final class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public function backoff(): array { return [60, 300, 900]; }
    public function __construct(public readonly ProviderWebhookEnvelope $envelope) {}
    public function handle(InboundMimeParser $parser, InboundRecipientResolver $resolver, IngestInboundEmailAction $ingest, InboundMetricsRecorder $metrics): void
    {
        $started = microtime(true); $metrics->record(null, 'started', ProcessingStage::Parse, ProcessingLogStatus::Started, ['queue_delay_ms' => max(0, (int) ((microtime(true) - $this->envelope->receivedAt->getTimestamp()) * 1000))]);
        $parsed = $parser->parse($this->envelope);
        $metrics->record(null, 'parsed', ProcessingStage::Parse, ProcessingLogStatus::Success, ['duration_ms' => (int) ((microtime(true) - $started) * 1000)]);
        $resolution = $resolver->resolve(new RecipientInput($parsed->recipientEmail, true));
        $metrics->record(null, $resolution->code->value, ProcessingStage::Receive, ProcessingLogStatus::Success, ['recipient_code' => $resolution->code->value]);
        if ($resolution->code->value !== 'resolved') { $metrics->record(null, 'rejected'); return; }
        $ingest->execute($parsed, $resolution);
    }
    public function failed(Throwable $exception): void
    {
        $email = AppModelsEmail::query()->where('message_id', $this->envelope->providerMessageId)->first();
        if ($email !== null) {
            app(AppServicesInboundInboundFailureService::class)->record((string) $email->getKey(), AppEnumsProcessingStage::Parse, 'inbound_retry_exhausted', $this->attempts());
        }
    }
}
