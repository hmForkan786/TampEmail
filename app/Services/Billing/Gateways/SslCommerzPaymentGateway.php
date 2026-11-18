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
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Exceptions\Billing\SslCommerzException;
use App\Models\BillingOrder;
use App\Models\User;
use App\Services\Billing\SslCommerz\SslCommerzApiClient;
use App\Services\Billing\SslCommerz\SslCommerzStoreResolver;
use App\Services\Billing\SslCommerz\SslCommerzValidationClient;
use Illuminate\Support\Facades\URL;

final readonly class SslCommerzPaymentGateway implements PaymentGateway
{
    public function __construct(
        private SslCommerzApiClient $api,
        private SslCommerzStoreResolver $stores,
        private SslCommerzValidationClient $validation,
    ) {}

    public function name(): string
    {
        return 'sslcommerz';
    }

    public function createCheckout(CreateCheckoutData $data): CheckoutSessionResult
    {
        if (config('billing.sslcommerz.maintenance_mode', false)) {
            throw new SslCommerzException('SSLCommerz is temporarily unavailable.');
        }
        $currency = strtoupper($data->currency);
        if ($data->amountMinor <= 0 || ! in_array($currency, (array) config('billing.sslcommerz.allowed_currencies', ['BDT']), true)) {
            throw new SslCommerzException('SSLCommerz currency or amount is unsupported.');
        }
        $order = BillingOrder::query()->findOrFail($data->billingOrderId);
        $user = User::query()->findOrFail($data->userId);
        $store = $this->stores->resolve($order);
        $supportPhone = trim((string) config('billing.sslcommerz.checkout.support_phone', ''));
        if ($supportPhone === '') {
            throw new SslCommerzException('SSLCommerz customer contact configuration is incomplete.');
        }
        $transactionId = 'sc'.substr(hash('sha256', $data->billingOrderId.'|'.$data->idempotencyKey), 0, 27);
        $return = fn (string $outcome): string => URL::temporarySignedRoute('billing.return', now()->addHour(), [
            'provider' => $this->name(), 'order' => $data->billingOrderId, 'outcome' => $outcome,
        ]);
        $response = $this->api->createSession($store, [
            'total_amount' => number_format($data->amountMinor / 100, 2, '.', ''),
            'currency' => $currency, 'tran_id' => $transactionId,
            'success_url' => $return('success'), 'fail_url' => $return('fail'), 'cancel_url' => $return('cancel'),
            'ipn_url' => route('api.v1.billing.providers.callback', ['provider' => $this->name()]),
            'product_name' => (string) config('billing.sslcommerz.checkout.product_name', 'SaaS subscription'),
            'product_category' => (string) config('billing.sslcommerz.checkout.product_category', 'software'),
            'product_profile' => 'non-physical-goods', 'shipping_method' => 'NO',
            'cus_name' => trim((string) ($user->name ?? '')) ?: 'Customer',
            'cus_email' => (string) $user->email,
            'cus_add1' => (string) config('billing.sslcommerz.checkout.neutral_address', 'Not applicable'),
            'cus_city' => (string) config('billing.sslcommerz.checkout.neutral_city', 'Dhaka'),
            'cus_country' => (string) config('billing.sslcommerz.checkout.neutral_country', 'Bangladesh'),
            'cus_phone' => $supportPhone,
            'value_a' => $data->billingOrderId, 'value_b' => $store->key,
        ]);
        $status = strtoupper((string) ($response['status'] ?? ''));
        $sessionKey = trim((string) ($response['sessionkey'] ?? ''));
        $url = trim((string) ($response['GatewayPageURL'] ?? ''));
        if ($status !== 'SUCCESS' || $sessionKey === '' || filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            throw new SslCommerzException('SSLCommerz checkout response is invalid.');
        }
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $order->forceFill(['metadata' => [...$metadata, 'sslcommerz_tran_id' => $transactionId, 'sslcommerz_store' => $store->key, 'sslcommerz_environment' => $store->environment]])->save();

        return new CheckoutSessionResult($this->name(), $sessionKey, $url);
    }

    public function verifyWebhook(WebhookRequestData $data): VerifiedProviderEvent
    {
        /** @var array<string, string> $payload */
        $payload = $data->payload;
        $valid = $this->validation->validateIpn($payload);
        $status = $valid['status'];

        return new VerifiedProviderEvent(
            $this->name(), 'sslcommerz_'.hash('sha256', (string) $valid['val_id'].'|'.(string) $valid['tran_id']),
            'payment.'.strtolower((string) $valid['status_text']), (string) $valid['bank_tran_id'] ?: (string) $valid['tran_id'],
            (string) $valid['billing_order_id'], (int) $valid['amount_minor'], (string) $valid['currency'],
            PaymentTransactionType::Sale, $status->isFinancialSuccess(), paymentStatus: $status,
            providerOrderReference: (string) $valid['tran_id'], providerSessionId: (string) $valid['sessionkey'],
            rawPayloadFingerprint: hash('sha256', $data->rawBody), signatureVerified: true,
            safeMetadata: ['store_key' => $valid['store_key']],
        );
    }

    public function queryPayment(QueryPaymentData $data): PaymentQueryResult
    {
        $order = BillingOrder::query()->findOrFail($data->billingOrderId);
        $valid = $this->validation->query($order, $data->providerTransactionId);
        $status = $valid['status'];

        return new PaymentQueryResult((string) $valid['bank_tran_id'] ?: (string) $valid['tran_id'], $data->billingOrderId, (int) $valid['amount_minor'], (string) $valid['currency'], $status->isFinancialSuccess(), $status, 'sslcommerz_query_'.hash('sha256', (string) $valid['val_id'].'|'.(string) $valid['status_text']));
    }

    public function refund(RefundPaymentData $data): RefundResult
    {
        throw new PaymentVerificationException('SSLCommerz refunds are not supported by this adapter.');
    }

    public function supports(PaymentCapability $capability): bool
    {
        return in_array($capability, [PaymentCapability::Checkout, PaymentCapability::PaymentQuery, PaymentCapability::WebhookVerification], true);
    }
}
