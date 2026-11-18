<?php

use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentProviderEventStatus;
use App\Jobs\Billing\ProcessPaymentProviderEventJob;
use App\Models\AuditLog;
use App\Models\PaymentProviderEvent;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('deduplicates provider webhook events and audits duplicates', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $processing = app(PaymentProcessingService::class);

    $request = billingSuccessWebhook((string) $order->getKey(), $order->total_minor, eventId: 'evt-webhook-1');
    $first = $processing->ingestWebhook($request);
    $second = $processing->ingestWebhook($request);

    expect($second->getKey())->toBe($first->getKey())
        ->and(PaymentProviderEvent::query()->count())->toBe(1);

    $first->forceFill(['status' => PaymentProviderEventStatus::Processed])->save();
    $processing->ingestWebhook($request);
    expect(AuditLog::query()->where('action', 'billing.webhook.duplicate')->count())->toBe(1);
});

it('processes stored events idempotently through the async job path', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $request = billingSuccessWebhook((string) $order->getKey(), $order->total_minor, eventId: 'evt-job-1');

    $event = app(PaymentProcessingService::class)->ingestWebhook($request);
    (new ProcessPaymentProviderEventJob((string) $event->getKey()))->handle(app(PaymentProcessingService::class));
    (new ProcessPaymentProviderEventJob((string) $event->getKey()))->handle(app(PaymentProcessingService::class));

    expect($order->fresh()->status)->toBe(BillingOrderStatus::Paid)
        ->and($event->fresh()->status)->toBe(PaymentProviderEventStatus::Processed);
});

it('redacts sensitive webhook payload fields before persistence', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $payload = [
        'event_id' => 'evt-redact',
        'billing_order_id' => (string) $order->getKey(),
        'amount_minor' => $order->total_minor,
        'currency' => 'USD',
        'succeeded' => true,
        'provider_transaction_id' => 'fake_tx_redact',
        'card_number' => '4111111111111111',
        'cvv' => '123',
    ];

    $event = app(PaymentProcessingService::class)->ingestWebhook(new WebhookRequestData(
        provider: 'fake',
        headers: [],
        payload: $payload,
        rawBody: json_encode($payload, JSON_THROW_ON_ERROR),
    ));

    expect($event->payload_redacted['card_number'])->toBe('[REDACTED]')
        ->and($event->payload_redacted['cvv'])->toBe('[REDACTED]');
});
