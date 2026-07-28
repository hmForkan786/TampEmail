<?php

declare(strict_types=1);

namespace App\Services\Billing\SslCommerz;

use App\DTOs\Billing\SslCommerzStore;
use App\Exceptions\Billing\SslCommerzException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class SslCommerzApiClient
{
    /** @param array<string, scalar> $fields @return array<string, mixed> */
    public function createSession(SslCommerzStore $store, array $fields): array
    {
        return $this->request('post', $store->baseUrl.'/gwprocess/v4/api.php', [
            ...$fields, 'store_id' => $store->storeId, 'store_passwd' => $store->storePassword,
        ]);
    }

    /** @param array<string, scalar> $query @return array<string, mixed> */
    public function get(SslCommerzStore $store, string $path, array $query): array
    {
        return $this->request('get', $store->baseUrl.$path, [
            ...$query, 'store_id' => $store->storeId, 'store_passwd' => $store->storePassword, 'format' => 'json',
        ]);
    }

    /** @param array<string, scalar> $data @return array<string, mixed> */
    private function request(string $method, string $url, array $data): array
    {
        try {
            $request = Http::asForm()
                ->connectTimeout((int) config('billing.sslcommerz.api.connect_timeout_seconds', 10))
                ->timeout((int) config('billing.sslcommerz.api.timeout_seconds', 30))
                ->retry((int) config('billing.sslcommerz.validation.retry_attempts', 3), 500, throw: false);
            $response = $method === 'post' ? $request->post($url, $data) : $request->get($url, $data);
        } catch (ConnectionException) {
            throw new SslCommerzException('SSLCommerz transport failed.');
        }
        if (! $response->successful()) {
            throw new SslCommerzException('SSLCommerz provider request failed.');
        }
        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new SslCommerzException('SSLCommerz returned an invalid response.');
        }

        return $decoded;
    }
}
