<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use Illuminate\Support\Facades\Cache;

final readonly class StripeHealthCheckService
{
    public function __construct(private StripeAccountResolver $accounts, private StripeApiClient $api) {}

    /** @return array{healthy:bool,environment:string,account_key:string,checked_at:string} */
    public function check(): array
    {
        return Cache::remember('billing:stripe:health', (int) config('billing.stripe.health_check.cache_ttl_seconds', 60), function (): array {
            try {
                $account = $this->accounts->resolve();
                $this->api->retrieveAccount($account);

                return ['healthy' => true, 'environment' => $account->environment, 'account_key' => $account->key, 'checked_at' => now()->toIso8601String()];
            } catch (\Throwable) {
                return ['healthy' => false, 'environment' => 'unknown', 'account_key' => 'unknown', 'checked_at' => now()->toIso8601String()];
            }
        });
    }
}
