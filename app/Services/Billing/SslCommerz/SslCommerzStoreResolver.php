<?php

declare(strict_types=1);

namespace App\Services\Billing\SslCommerz;

use App\DTOs\Billing\SslCommerzStore;
use App\Exceptions\Billing\SslCommerzException;
use App\Models\BillingOrder;

final readonly class SslCommerzStoreResolver
{
    public function __construct(private SslCommerzEndpointResolver $endpoints) {}

    public function resolve(?BillingOrder $order = null, ?string $explicitKey = null): SslCommerzStore
    {
        $metadata = is_array($order?->metadata) ? $order->metadata : [];
        $key = $explicitKey ?? (is_string($metadata['sslcommerz_store'] ?? null) ? $metadata['sslcommerz_store'] : null)
            ?? (string) config('billing.sslcommerz.default_store', 'default');
        $store = config("billing.sslcommerz.stores.{$key}");
        if (! is_array($store) || ($store['enabled'] ?? false) !== true) {
            throw new SslCommerzException('SSLCommerz store is unavailable.');
        }
        $environment = $this->endpoints->environment();
        if (isset($store['environment']) && $store['environment'] !== $environment) {
            throw new SslCommerzException('SSLCommerz store environment mismatch.');
        }
        $id = trim((string) ($store['store_id'] ?? ''));
        $password = (string) ($store['store_password'] ?? '');
        if ($id === '' || $password === '') {
            throw new SslCommerzException('SSLCommerz store credentials are incomplete.');
        }

        return new SslCommerzStore($key, $id, $password, $environment, $this->endpoints->baseUrl());
    }
}
