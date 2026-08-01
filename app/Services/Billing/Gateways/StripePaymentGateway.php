<?php

declare(strict_types=1);

namespace App\Services\Billing\Gateways;

use App\Contracts\Billing\PaymentGateway;
use App\DTOs\Billing\CheckoutSessionResult;
use App\DTOs\Billing\CreateCheckoutData;
use App\DTOs\Billing\PaymentQueryResult;
use App\DTOs\Billing\QueryPaymentData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\RefundResult;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\DTOs\Billing\WebhookRequestData;
use App\Enums\PaymentCapability;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Exceptions\Billing\StripeException;
use App\Models\BillingOrder;
use App\Models\User;
use App\Services\Billing\Stripe\StripeAccountResolver;
use App\Services\Billing\Stripe\StripeApiClient;
use App\Services\Billing\Stripe\StripeEventNormalizer;
use Illuminate\Support\Facades\URL;

final readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(
        private StripeAccountResolver $accounts,
        private StripeApiClient $api,
        private StripeEventNormalizer $normalizer,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createCheckout(CreateCheckoutData $data): CheckoutSessionResult
    {
        if (config('billing.stripe.maintenance_mode', false)) {
            throw new StripeException('Stripe is temporarily unavailable.');
        }
        $currency = strtolower($data->currency);
        if ($data->amountMinor <= 0 || ! in_array($currency, (array) config('billing.stripe.allowed_currencies', ['usd']), true)) {
            throw new StripeException('Stripe currency or amount is unsupported.');
        }
        $order = BillingOrder::query()->findOrFail($data->billingOrderId);
        $account = $this->accounts->resolve($order);
        $user = User::query()->findOrFail($data->userId);
        $successUrl = URL::temporarySignedRoute('billing.return', now()->addHour(), ['provider' => 'stripe', 'order' => $order->getKey(), 'outcome' => 'success']);
        $cancelUrl = URL::temporarySignedRoute('billing.return', now()->addHour(), ['provider' => 'stripe', 'order' => $order->getKey(), 'outcome' => 'cancel']);
        $metadata = ['billing_order_id' => (string) $order->getKey(), 'account_key' => $account->key, 'environment' => $account->environment];
        $response = $this->api->createCheckout($account, [
            'mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $order->getKey(), 'customer_email' => (string) $user->email,
            'payment_method_types' => (array) config('billing.stripe.checkout.payment_method_types', ['card']),
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => $currency, 'unit_amount' => $data->amountMinor, 'product_data' => ['name' => 'SaaS subscription']],
            ]],
            'metadata' => $metadata, 'payment_intent_data' => ['metadata' => $metadata],
            'expires_at' => now()->addMinutes((int) config('billing.stripe.checkout.expires_after_minutes', 30))->timestamp,
        ], $data->idempotencyKey);
        $id = trim((string) ($response['id'] ?? ''));
        $url = trim((string) ($response['url'] ?? ''));
        if ($id === '' || ! str_starts_with($id, 'cs_') || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')
            || ($response['mode'] ?? null) !== 'payment' || ($response['client_reference_id'] ?? null) !== $order->getKey()) {
            throw new StripeException('Stripe checkout response is invalid.');
        }
        $existingMetadata = is_array($order->metadata) ? $order->metadata : [];
        $intent = is_string($response['payment_intent'] ?? null) ? $response['payment_intent'] : null;
        $order->forceFill(['metadata' => [...$existingMetadata, 'stripe_account_key' => $account->key, 'stripe_environment' => $account->environment, 'stripe_payment_intent_id' => $intent]])->save();

        return new CheckoutSessionResult('stripe', $id, $url, isset($response['expires_at']) ? date(DATE_ATOM, (int) $response['expires_at']) : null);
    }

    public function verifyWebhook(WebhookRequestData $data): VerifiedProviderEvent
    {
        return $this->normalizer->normalize($data->payload, $data->rawBody);
    }

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult
    {
        $order = BillingOrder::query()->findOrFail($data->billingOrderId);
        $account = $this->accounts->resolve($order);
        $session = $this->api->retrieveCheckout($account, $data->providerTransactionId);
        $intent = $session['payment_intent'] ?? null;
        if (! is_array($intent)) {
            throw new PaymentVerificationException('Stripe PaymentIntent is unavailable.');
        }
        $status = $this->normalizer->status('payment_intent.status_query', (string) ($intent['status'] ?? ''));
        $amount = (int) ($intent['amount_received'] ?? $intent['amount'] ?? -1);
        $currency = strtoupper((string) ($intent['currency'] ?? ''));
        if (($session['client_reference_id'] ?? null) !== $order->getKey() || $amount !== $order->total_minor || $currency !== $order->currency) {
            throw new PaymentVerificationException('Stripe query mismatch.');
        }

        return new PaymentQueryResult((string) ($intent['id'] ?? ''), (string) $order->getKey(), $amount, $currency, $status->isFinancialSuccess(), $status, 'stripe_query_'.hash('sha256', (string) ($intent['id'] ?? '').'|'.$status->value));
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        throw new PaymentVerificationException('Stripe refunds are not enabled in this adapter.');
    }

    public function supports(PaymentCapability $capability): bool
    {
        return in_array($capability, [PaymentCapability::Checkout, PaymentCapability::PaymentQuery, PaymentCapability::WebhookVerification], true);
    }
}
