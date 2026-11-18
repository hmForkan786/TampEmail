<?php

declare(strict_types=1);

use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Billing\ProcessPaymentProviderEventJob;
use App\Jobs\Billing\SyncPaymentStatusJob;
use App\Models\AuditLog;
use App\Models\PaymentProviderEvent;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentCallbackIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

function signedFakeCallbackHeaders(string $raw, string $nonce = 'fixture-nonce-00000001', ?string $timestamp = null): array
{
    $timestamp ??= (string) now()->timestamp;
    $secret = 'obvious-test-only-webhook-secret';
    config()->set('billing.webhook_security.environment', 'testing');
    config()->set('billing.webhook_security.providers.fake.enabled', true);
    config()->set('billing.webhook_security.providers.fake.secret', $secret);
    $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$raw}", $secret);

    return ['Content-Type' => 'application/json', 'X-Fake-Signature' => 'v1='.$signature, 'X-Fake-Timestamp' => $timestamp, 'X-Fake-Nonce' => $nonce];
}

it('durably accepts a callback and dispatches processing after commit', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $payload = [
        'event_id' => 'callback-1', 'event_type' => 'payment.succeeded',
        'billing_order_id' => $order->getKey(), 'provider_transaction_id' => 'fake_tx_callback_1',
        'amount_minor' => $order->total_minor, 'currency' => $order->currency,
        'payment_status' => 'succeeded', 'succeeded' => true, 'card_number' => '4111111111111111',
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $this->call('POST', '/api/v1/billing/providers/fake/callback', [], [], [], array_merge(
        ['CONTENT_TYPE' => 'application/json'],
        collect(signedFakeCallbackHeaders($raw))->except('Content-Type')->mapWithKeys(fn ($value, $key) => ['HTTP_'.strtoupper(str_replace('-', '_', $key)) => $value])->all(),
    ), $raw)
        ->assertAccepted()->assertJsonPath('accepted', true);

    expect(PaymentProviderEvent::query()->count())->toBe(1)
        ->and(PaymentProviderEvent::query()->first()->payload_redacted['card_number'])->toBe('[REDACTED]');
    Queue::assertPushed(ProcessPaymentProviderEventJob::class);
});

it('acknowledges exact replays and rejects payload conflicts', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $payload = [
        'event_id' => 'callback-replay', 'billing_order_id' => $order->getKey(),
        'provider_transaction_id' => 'fake_tx_replay', 'amount_minor' => $order->total_minor,
        'currency' => 'USD', 'payment_status' => 'succeeded', 'succeeded' => true,
    ];
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $request = new WebhookRequestData('fake', [], $payload, $raw);
    $service = app(PaymentCallbackIngestionService::class);

    expect($service->ingest($request)->duplicate)->toBeFalse()
        ->and($service->ingest($request)->duplicate)->toBeTrue();

    $changed = array_merge($payload, ['amount_minor' => 1]);
    expect(fn () => $service->ingest(new WebhookRequestData('fake', [], $changed, json_encode($changed, JSON_THROW_ON_ERROR))))
        ->toThrow(PaymentVerificationException::class)
        ->and(AuditLog::query()->where('action', 'billing.callback.payload_conflict')->exists())->toBeTrue();
});

it('rejects invalid fake callback signatures when verification is required', function (): void {
    config()->set('billing.webhook_security.providers.fake.enabled', true);
    config()->set('billing.webhook_security.providers.fake.secret', 'obvious-test-only-webhook-secret');
    $payload = ['event_id' => 'bad-signature'];

    $this->withHeaders(['X-Fake-Signature' => 'v1='.str_repeat('0', 64), 'X-Fake-Timestamp' => (string) now()->timestamp, 'X-Fake-Nonce' => 'fixture-nonce-00000002'])
        ->postJson('/api/v1/billing/providers/fake/callback', $payload)
        ->assertUnauthorized()->assertJsonPath('accepted', false);
});

it('keeps signed browser returns non-authoritative and only queues synchronization', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $url = URL::signedRoute('billing.return', ['provider' => 'fake', 'order' => $order->getKey()]);

    $this->get($url)->assertRedirect();

    expect($order->fresh()->status)->toBe(BillingOrderStatus::Pending);
    Queue::assertPushed(SyncPaymentStatusJob::class);
});
