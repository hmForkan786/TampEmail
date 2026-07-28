<?php

declare(strict_types=1);

use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\QueryPaymentData;
use App\DTOs\Billing\StripeAccount;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentCapability;
use App\Exceptions\Billing\StripeException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Models\PaymentProviderEvent;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\Gateways\StripePaymentGateway;
use App\Services\Billing\PaymentGatewayResolver;
use App\Services\Billing\PaymentProcessingService;
use App\Services\Billing\Stripe\StripeAccountResolver;
use App\Services\Billing\Stripe\StripeApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

function configureStripeFixtures(): void
{
    config()->set('billing.enabled_gateways', ['fake', 'sslcommerz', 'stripe']);
    config()->set('billing.stripe.environment', 'test');
    config()->set('billing.stripe.allowed_currencies', ['usd']);
    config()->set('billing.stripe.accounts.default', [
        'enabled' => true, 'environment' => 'test', 'secret_key' => 'sk_test_obvious_fixture',
        'publishable_key' => 'pk_test_obvious_fixture', 'webhook_secrets' => ['whsec_obvious_fixture'], 'stripe_account' => null,
    ]);
    config()->set('billing.webhook_security.providers.stripe.enabled', true);
    config()->set('billing.webhook_security.environment', 'testing');
}

function stripeFixtureHeader(string $raw, string $secret = 'whsec_obvious_fixture', ?int $timestamp = null): string
{
    $timestamp ??= now()->timestamp;

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
}

beforeEach(fn () => configureStripeFixtures());

it('resolves a redacted test account and rejects test live mismatches', function (): void {
    $account = app(StripeAccountResolver::class)->resolve(requireWebhookSecret: true);
    expect($account->environment)->toBe('test')
        ->and(json_encode($account, JSON_THROW_ON_ERROR))->not->toContain('sk_test_')->not->toContain('whsec_');

    config()->set('billing.stripe.environment', 'live');
    expect(fn () => app(StripeAccountResolver::class)->resolve())->toThrow(StripeException::class);
});

it('creates a hosted payment-mode Checkout Session with stable API idempotency', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'stripe'));
    $spy = new class extends StripeApiClient
    {
        public array $parameters = [];

        public string $idempotencyKey = '';

        public function createCheckout(StripeAccount $account, array $parameters, string $idempotencyKey): array
        {
            $this->parameters = $parameters;
            $this->idempotencyKey = $idempotencyKey;

            return ['id' => 'cs_test_fixture_1', 'url' => 'https://checkout.stripe.com/c/pay/fixture', 'mode' => 'payment', 'client_reference_id' => $parameters['client_reference_id'], 'payment_intent' => 'pi_fixture_1', 'expires_at' => now()->addMinutes(30)->timestamp];
        }
    };
    app()->instance(StripeApiClient::class, $spy);

    $result = app(StripePaymentGateway::class)->createCheckout(new CreateCheckoutData(
        (string) $order->getKey(), (string) $user->getKey(), 'stripe', $order->total_minor, $order->currency,
        'https://app.test/success', 'https://app.test/cancel', 'stripe-idempotency-fixture',
    ));

    expect($result->providerReference)->toBe('cs_test_fixture_1')
        ->and($spy->parameters['mode'])->toBe('payment')
        ->and($spy->parameters['line_items'][0]['price_data']['unit_amount'])->toBe($order->total_minor)
        ->and($spy->idempotencyKey)->toBe('stripe-idempotency-fixture')
        ->and($order->fresh()->metadata['stripe_account_key'])->toBe('default');
});

it('verifies exact Stripe raw bytes then uses Prompt 638 and activates once', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'stripe'));
    $order->forceFill([
        'provider' => 'stripe', 'provider_reference' => 'cs_test_fixture_2',
        'metadata' => ['stripe_account_key' => 'default', 'stripe_environment' => 'test', 'stripe_payment_intent_id' => 'pi_fixture_2'],
    ])->save();
    $event = [
        'id' => 'evt_fixture_payment_success_1', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'created' => now()->timestamp,
        'data' => ['object' => [
            'id' => 'pi_fixture_2', 'object' => 'payment_intent', 'status' => 'succeeded',
            'amount' => $order->total_minor, 'amount_received' => $order->total_minor, 'currency' => strtolower($order->currency),
            'metadata' => ['billing_order_id' => (string) $order->getKey(), 'account_key' => 'default', 'checkout_session_id' => 'cs_test_fixture_2'],
        ]],
    ];
    $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeFixtureHeader($raw)];

    $this->call('POST', '/api/v1/billing/providers/stripe/callback', [], [], [], $server, $raw)->assertOk()->assertJson(['received' => true]);
    $this->call('POST', '/api/v1/billing/providers/stripe/callback', [], [], [], $server, $raw)->assertOk();

    $stored = PaymentProviderEvent::query()->where('provider', 'stripe')->firstOrFail();
    $verified = app(StripePaymentGateway::class)->verifyWebhook(new WebhookRequestData('stripe', [], $event, $raw));
    app(PaymentProcessingService::class)->processStoredEvent($stored, $verified);

    expect(PaymentProviderEvent::query()->where('provider', 'stripe')->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(BillingOrderStatus::Paid);
    Queue::assertPushed(ActivatePaidSubscriptionJob::class, 1);
});

it('rejects modified payloads stale signatures and money mismatches before mutation', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'stripe'));
    $order->forceFill(['provider' => 'stripe', 'provider_reference' => 'cs_test_fixture_3', 'metadata' => ['stripe_account_key' => 'default']])->save();
    $event = ['id' => 'evt_fixture_rejected_0001', 'object' => 'event', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
        'id' => 'pi_fixture_3', 'amount_received' => 1, 'currency' => strtolower($order->currency),
        'metadata' => ['billing_order_id' => $order->getKey(), 'account_key' => 'default', 'checkout_session_id' => 'cs_test_fixture_3'],
    ]]];
    $raw = json_encode($event, JSON_THROW_ON_ERROR);

    $this->call('POST', '/api/v1/billing/providers/stripe/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeFixtureHeader($raw, timestamp: now()->subMinutes(10)->timestamp),
    ], $raw)->assertUnauthorized();
    $this->call('POST', '/api/v1/billing/providers/stripe/callback', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => stripeFixtureHeader($raw),
    ], $raw)->assertBadRequest();
    expect(PaymentProviderEvent::query()->count())->toBe(0);
});

it('declares only completed Stripe capabilities', function (): void {
    $gateway = app(PaymentGatewayResolver::class)->resolve('stripe');
    expect($gateway->supports(PaymentCapability::Checkout))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::PaymentQuery))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::WebhookVerification))->toBeTrue()
        ->and($gateway->supports(PaymentCapability::Refund))->toBeFalse();
});

it('queries the original Checkout Session account and normalizes its PaymentIntent', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'stripe'));
    $order->forceFill(['provider' => 'stripe', 'provider_reference' => 'cs_test_query_1', 'metadata' => ['stripe_account_key' => 'default']])->save();
    $api = new class extends StripeApiClient
    {
        public string $orderId;

        public int $amount;

        public string $currency;

        public function retrieveCheckout(StripeAccount $account, string $sessionId): array
        {
            return [
                'id' => $sessionId, 'client_reference_id' => $this->orderId,
                'payment_intent' => ['id' => 'pi_query_1', 'status' => 'succeeded', 'amount_received' => $this->amount, 'currency' => strtolower($this->currency)],
            ];
        }
    };
    $api->orderId = (string) $order->getKey();
    $api->amount = $order->total_minor;
    $api->currency = $order->currency;
    app()->instance(StripeApiClient::class, $api);

    $result = app(StripePaymentGateway::class)->queryPayment(new QueryPaymentData('stripe', 'cs_test_query_1', (string) $order->getKey(), $order->total_minor, $order->currency));

    expect($result->succeeded)->toBeTrue()
        ->and($result->providerTransactionId)->toBe('pi_query_1')
        ->and($result->amountMinor)->toBe($order->total_minor);
});
