<?php

declare(strict_types=1);

namespace App\Services\Billing\SslCommerz;

use App\Exceptions\Billing\SslCommerzException;

final class SslCommerzEndpointResolver
{
    public function environment(): string
    {
        $environment = strtolower((string) config('billing.sslcommerz.environment', 'sandbox'));
        if (! in_array($environment, ['sandbox', 'production'], true)) {
            throw new SslCommerzException('SSLCommerz environment configuration is invalid.');
        }

        return $environment;
    }

    public function baseUrl(): string
    {
        $key = $this->environment() === 'production' ? 'production_base_url' : 'sandbox_base_url';
        $url = rtrim((string) config("billing.sslcommerz.api.{$key}"), '/');
        if (! str_starts_with($url, 'https://')) {
            throw new SslCommerzException('SSLCommerz endpoint configuration is invalid.');
        }

        return $url;
    }
}
