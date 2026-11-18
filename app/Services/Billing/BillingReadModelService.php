<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingOrder;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentTransaction;

/** Internal read model for future admin/ops visibility. */
final class BillingReadModelService
{
    /** @return array<string, mixed> */
    public function orderSummary(string $billingOrderId): array
    {
        $order = BillingOrder::query()
            ->with(['plan', 'subscription', 'paymentTransactions'])
            ->findOrFail($billingOrderId);

        return [
            'order' => [
                'id' => $order->getKey(),
                'status' => $order->status->value,
                'type' => $order->type->value,
                'total_minor' => $order->total_minor,
                'currency' => $order->currency,
                'provider' => $order->provider,
                'provider_reference' => $order->provider_reference,
                'paid_at' => $order->paid_at?->toIso8601String(),
                'subscription_id' => $order->subscription_id,
                'activation_status' => $order->metadata['activation_status'] ?? null,
                'reconciliation_reason' => $order->metadata['reconciliation_reason'] ?? null,
            ],
            'transactions' => $order->paymentTransactions->map(fn (PaymentTransaction $tx): array => [
                'id' => $tx->getKey(),
                'type' => $tx->type->value,
                'status' => $tx->status->value,
                'amount_minor' => $tx->amount_minor,
                'currency' => $tx->currency,
                'provider_transaction_id' => $tx->provider_transaction_id,
                'processed_at' => $tx->processed_at?->toIso8601String(),
                'failure_code' => $tx->failure_code,
            ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function providerEventSummary(string $eventId): array
    {
        $event = PaymentProviderEvent::query()->findOrFail($eventId);

        return [
            'id' => $event->getKey(),
            'provider' => $event->provider,
            'provider_event_id' => $event->provider_event_id,
            'event_type' => $event->event_type,
            'status' => $event->status->value,
            'received_at' => $event->received_at->toIso8601String(),
            'processed_at' => $event->processed_at?->toIso8601String(),
            'attempts' => $event->attempts,
            'last_error' => $event->last_error,
        ];
    }
}
