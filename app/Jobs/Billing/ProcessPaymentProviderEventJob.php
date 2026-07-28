<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\PaymentProviderEventStatus;
use App\Enums\PaymentTransactionType;
use App\Models\PaymentProviderEvent;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessPaymentProviderEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function __construct(public readonly string $paymentProviderEventId) {}

    public function handle(PaymentProcessingService $processing): void
    {
        $event = PaymentProviderEvent::query()->findOrFail($this->paymentProviderEventId);

        if ($event->status === PaymentProviderEventStatus::Processed) {
            return;
        }

        $payload = $event->payload_redacted ?? [];
        $verified = new VerifiedProviderEvent(
            provider: $event->provider,
            providerEventId: $event->provider_event_id,
            eventType: $event->event_type,
            providerTransactionId: (string) ($payload['provider_transaction_id'] ?? $event->provider_event_id),
            billingOrderId: (string) ($payload['billing_order_id'] ?? ''),
            amountMinor: (int) ($payload['amount_minor'] ?? 0),
            currency: strtoupper((string) ($payload['currency'] ?? 'USD')),
            transactionType: PaymentTransactionType::Sale,
            succeeded: (bool) ($payload['succeeded'] ?? false),
            failureCode: isset($payload['failure_code']) ? (string) $payload['failure_code'] : null,
            failureMessage: isset($payload['failure_message']) ? (string) $payload['failure_message'] : null,
        );

        $processing->processStoredEvent($event, $verified);
    }
}
