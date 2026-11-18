<?php

declare(strict_types=1);

namespace App\Services\Billing\SslCommerz;

use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\BillingOrder;

final class SslCommerzValidationClient
{
    /** @var array<string, array<string, mixed>> */
    private array $requestCache = [];

    public function __construct(
        private readonly SslCommerzApiClient $api,
        private readonly SslCommerzStoreResolver $stores,
    ) {}

    /**
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     */
    public function validateIpn(array $payload): array
    {
        $valId = trim($payload['val_id'] ?? '');
        $orderId = trim($payload['value_a'] ?? '');
        if ($valId === '' || preg_match('/^[0-9A-Za-z_-]{8,100}$/D', $valId) !== 1 || preg_match('/^[0-9a-f-]{36}$/i', $orderId) !== 1) {
            throw new PaymentVerificationException('SSLCommerz validation identifiers are invalid.');
        }
        if (isset($this->requestCache[$valId])) {
            return $this->requestCache[$valId];
        }
        $order = BillingOrder::query()->findOrFail($orderId);
        $store = $this->stores->resolve($order);
        $response = $this->api->get($store, '/validator/api/validationserverAPI.php', ['val_id' => $valId]);

        return $this->requestCache[$valId] = $this->normalizeAndAssert($response, $order, $store->key, $payload['tran_id'] ?? null);
    }

    /** @return array<string, mixed> */
    public function query(BillingOrder $order, string $sessionKey): array
    {
        $store = $this->stores->resolve($order);
        $response = $this->api->get($store, '/validator/api/merchantTransIDvalidationAPI.php', ['sessionkey' => $sessionKey]);

        return $this->normalizeAndAssert($response, $order, $store->key);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizeAndAssert(array $response, BillingOrder $order, string $storeKey, ?string $expectedTransaction = null): array
    {
        $statusText = strtoupper((string) ($response['status'] ?? ''));
        $status = match ($statusText) {
            'VALID', 'VALIDATED' => ProviderPaymentStatus::Succeeded,
            'PENDING' => ProviderPaymentStatus::Pending,
            'FAILED', 'CANCELLED', 'INVALID_TRANSACTION' => ProviderPaymentStatus::Failed,
            default => ProviderPaymentStatus::Unknown,
        };
        if ($status === ProviderPaymentStatus::Unknown) {
            throw new PaymentVerificationException('SSLCommerz returned an unsupported status.');
        }
        $transactionId = (string) ($response['tran_id'] ?? '');
        $metadata = is_array($order->metadata) ? $order->metadata : [];
        $storedTransaction = (string) ($metadata['sslcommerz_tran_id'] ?? '');
        if ($transactionId === '' || ($expectedTransaction !== null && ! hash_equals($transactionId, $expectedTransaction))
            || $storedTransaction === '' || ! hash_equals($storedTransaction, $transactionId)) {
            throw new PaymentVerificationException('SSLCommerz transaction mismatch.');
        }
        $amount = (string) ($response['amount'] ?? $response['currency_amount'] ?? '');
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount) || $this->minor($amount) !== $order->total_minor) {
            throw new PaymentVerificationException('SSLCommerz amount mismatch.');
        }
        $currency = strtoupper((string) ($response['currency'] ?? $response['currency_type'] ?? ''));
        if ($currency !== strtoupper($order->currency)) {
            throw new PaymentVerificationException('SSLCommerz currency mismatch.');
        }

        return [
            'status' => $status, 'status_text' => $statusText, 'tran_id' => $transactionId,
            'val_id' => (string) ($response['val_id'] ?? ''), 'bank_tran_id' => (string) ($response['bank_tran_id'] ?? ''),
            'amount_minor' => $order->total_minor, 'currency' => $currency, 'billing_order_id' => (string) $order->getKey(),
            'store_key' => $storeKey, 'sessionkey' => (string) ($response['sessionkey'] ?? $order->provider_reference),
        ];
    }

    private function minor(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
