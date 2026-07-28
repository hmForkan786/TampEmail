<?php

declare(strict_types=1);

use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentCapability;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Models\PaymentProviderEvent;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\Gateways\SslCommerzPaymentGateway;
use App\Services\Billing\PaymentGatewayResolver;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

beforeEach(function (): void {
    config()->set('billing.enabled_gateways', ['fake', 'sslcommerz']);
    config()->set('billing.sslcommerz.enabled', true);
    config()->set('billing.sslcommerz.environment', 'sandbox');
    config()->set('billing.sslcommerz.allowed_currencies', ['USD', 'BDT']);
    config()->set('billing.sslcommerz.api.sandbox_base_url', 'https://sandbox.sslcommerz.com');
    config()->set('billing.sslcommerz.api.production_base_url', 'https://securepay.sslcommerz.com');
    config()->set('billing.sslcommerz.checkout.support_phone', '8801000000000');
    config()->set('billing.sslcommerz.stores.default', [
        'enabled' => true, 'environment' => 'sandbox', 'store_id' => 'fixture-store', 'store_password' => 'fixture-secret',
    ]);
    config()->set('billing.webhook_security.providers.sslcommerz.enabled', true);
    config()->set('billing.webhook_security.environment', 'testing');
});

it('creates a hosted checkout with the selected sandbox store and persists safe mapping', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'sslcommerz'));
    Http::fake(fn ($request) => Http::response([
        'status' => 'SUCCESS', 'sessionkey' => 'SESSION_FIXTURE_1',
        'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gwprocess/pay/SESSION_FIXTURE_1',
    ]));

    $result = app(SslCommerzPaymentGateway::class)->createCheckout(new CreateCheckoutData(
        (string) $order->getKey(), (string) $user->getKey(), 'sslcommerz', $order->total_minor,
        $order->currency, 'https://app.test/success', 'https://app.test/cancel', 'checkout-fixture-1',
    ));

    expect($result->provider)->toBe('sslcommerz')
        ->and($result->providerReference)->toBe('SESSION_FIXTURE_1')
        ->and($result->checkoutUrl)->toStartWith('https://sandbox.sslcommerz.com/')
        ->and($order->fresh()->metadata['sslcommerz_store'])->toBe('default')
        ->and(strlen($order->fresh()->metadata['sslcommerz_tran_id']))->toBeLessThanOrEqual(30);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://sandbox.sslcommerz.com/gwprocess/v4/api.php'
        && $request['store_id'] === 'fixture-store' && $request['store_passwd'] === 'fixture-secret'
        && $request['value_a'] === $order->getKey());
});

it('declares only implemented capabilities and fails refunds closed', function (): void {
    $gateway = app(PaymentGatewayResolver::class)->resolve('sslcommerz');
    expect($gateway)->toBeInstanceOf(SslCommerzPaymentGateway::class);
    expect($gateway->supports(PaymentCapability::Checkout))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::PaymentQuery))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::WebhookVerification))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::Refund))->toBeFalse();
    expect(fn () => $gateway->refund(new RefundPaymentData('sslcommerz', 'order', 'tx', 100, 'BDT', 'key')))
        ->toThrow(PaymentVerificationException::class);
});

it('accepts a validated form IPN through Prompt 639 and persists it through Prompt 638', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'sslcommerz'));
    $tranId = 'sc'.substr(hash('sha256', 'ipn-fixture'), 0, 27);
    $order->forceFill(['provider' => 'sslcommerz', 'provider_reference' => 'SESSION_IPN_1', 'metadata' => [
        'sslcommerz_tran_id' => $tranId, 'sslcommerz_store' => 'default', 'sslcommerz_environment' => 'sandbox',
    ]])->save();
    Http::fake([
        '*/validator/api/validationserverAPI.php*' => Http::response([
            'status' => 'VALIDATED', 'tran_id' => $tranId, 'val_id' => 'VALIDATION_ID_0001',
            'amount' => number_format($order->total_minor / 100, 2, '.', ''), 'currency' => $order->currency,
            'bank_tran_id' => 'BANK_TX_0001', 'sessionkey' => 'SESSION_IPN_1',
        ]),
    ]);
    $raw = http_build_query([
        'status' => 'VALID', 'tran_id' => $tranId, 'val_id' => 'VALIDATION_ID_0001',
        'amount' => number_format($order->total_minor / 100, 2, '.', ''), 'currency' => $order->currency,
        'value_a' => (string) $order->getKey(), 'value_b' => 'default',
    ]);

    $this->call('POST', '/api/v1/billing/providers/sslcommerz/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $raw)->assertOk()->assertSeeText('OK');
    $this->call('POST', '/api/v1/billing/providers/sslcommerz/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $raw)->assertOk()->assertSeeText('OK');

    $event = PaymentProviderEvent::query()->where('provider', 'sslcommerz')->firstOrFail();
    $parsed = [];
    parse_str($raw, $parsed);
    $verified = app(SslCommerzPaymentGateway::class)->verifyWebhook(new WebhookRequestData('sslcommerz', [], $parsed, $raw));
    app(PaymentProcessingService::class)->processStoredEvent($event, $verified);

    expect(PaymentProviderEvent::query()->where('provider', 'sslcommerz')->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Paid);
    Queue::assertPushed(ActivatePaidSubscriptionJob::class, 1);
    Http::assertSentCount(1);
});

it('rejects validation amount mismatches without creating provider events', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'sslcommerz'));
    $tranId = 'sc'.substr(hash('sha256', 'mismatch-fixture'), 0, 27);
    $order->forceFill(['provider' => 'sslcommerz', 'metadata' => ['sslcommerz_tran_id' => $tranId, 'sslcommerz_store' => 'default']])->save();
    Http::fake(fn () => Http::response(['status' => 'VALID', 'tran_id' => $tranId, 'val_id' => 'VALIDATION_ID_0002', 'amount' => '0.01', 'currency' => $order->currency]));
    $raw = http_build_query(['status' => 'VALID', 'tran_id' => $tranId, 'val_id' => 'VALIDATION_ID_0002', 'value_a' => $order->getKey()]);

    $this->call('POST', '/api/v1/billing/providers/sslcommerz/callback', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $raw)
        ->assertUnauthorized()->assertSeeText('REJECTED');
    expect(PaymentProviderEvent::query()->count())->toBe(0);
});
