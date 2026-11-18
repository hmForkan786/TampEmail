<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\CallbackIngestionResult;
use App\DTOs\Billing\WebhookRequestData;
use App\Models\PaymentProviderEvent;
use App\Services\Audit\AuditLogWriter;

final class PaymentCallbackIngestionService
{
    public function __construct(
        private readonly PaymentProcessingService $processing,
        private readonly AuditLogWriter $audit,
    ) {}

    public function ingest(WebhookRequestData $request): CallbackIngestionResult
    {
        $this->audit->write('billing.callback.received', null, null, null, ['provider' => strtolower($request->provider)]);
        $before = PaymentProviderEvent::query()->where('provider', strtolower($request->provider))->count();
        try {
            $event = $this->processing->ingestWebhook($request);
        } catch (\Throwable $exception) {
            $this->audit->write('billing.callback.rejected', null, null, null, [
                'provider' => strtolower($request->provider), 'reason_code' => 'verification_failed',
            ]);
            throw $exception;
        }
        $duplicate = PaymentProviderEvent::query()->where('provider', $event->provider)->count() === $before;
        $this->audit->write($duplicate ? 'billing.callback.duplicate' : 'billing.callback.verified', null, $event, null, [
            'provider' => $event->provider, 'internal_event_id' => $event->getKey(),
            'provider_event_id' => $event->provider_event_id,
        ]);

        return new CallbackIngestionResult(true, $duplicate, $event->provider_event_id, (string) $event->getKey(), $event->status->value, 'accepted');
    }
}
