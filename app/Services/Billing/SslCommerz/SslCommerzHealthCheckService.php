<?php

declare(strict_types=1);

namespace App\Services\Billing\SslCommerz;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class SslCommerzHealthCheckService
{
    public function __construct(private SslCommerzStoreResolver $stores) {}

    /** @return array{healthy:bool,environment:string,store_key:string,checked_at:string} */
    public function check(): array
    {
        $ttl = (int) config('billing.sslcommerz.health_check.cache_ttl_seconds', 60);

        return Cache::remember('billing:sslcommerz:health', $ttl, function (): array {
            try {
                $store = $this->stores->resolve();
                $healthy = Http::connectTimeout(3)->timeout(5)->get($store->baseUrl.'/public/tls/')->successful();

                return ['healthy' => $healthy, 'environment' => $store->environment, 'store_key' => $store->key, 'checked_at' => now()->toIso8601String()];
            } catch (\Throwable) {
                return ['healthy' => false, 'environment' => 'unknown', 'store_key' => 'unknown', 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
