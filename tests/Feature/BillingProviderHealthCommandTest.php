<?php

declare(strict_types=1);

use App\DTOs\Billing\StripeAccount;
use App\Exceptions\Billing\StripeException;
use App\Services\Billing\Stripe\StripeApiClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::forget('billing:stripe:health');
    Cache::forget('billing:sslcommerz:health');

    config()->set('billing.stripe.environment', 'test');
    config()->set('billing.stripe.accounts.default', [
        'enabled' => true,
        'environment' => 'test',
        'secret_key' => 'sk_test_obvious_fixture',
        'publishable_key' => 'pk_test_obvious_fixture',
        'webhook_secrets' => ['whsec_obvious_fixture'],
        'stripe_account' => null,
    ]);
    config()->set('billing.sslcommerz.enabled', true);
    config()->set('billing.sslcommerz.environment', 'sandbox');
    config()->set('billing.sslcommerz.api.sandbox_base_url', 'https://sandbox.sslcommerz.com');
    config()->set('billing.sslcommerz.stores.default', [
        'enabled' => true,
        'environment' => 'sandbox',
        'store_id' => 'fixture-store',
        'store_password' => 'fixture-secret',
    ]);
});

it('emits healthy json and exit 0 for billing stripe health', function (): void {
    app()->instance(StripeApiClient::class, new class extends StripeApiClient
    {
        public function retrieveAccount(StripeAccount $account): array
        {
            return ['id' => 'acct_fixture'];
        }
    });

    $exit = Artisan::call('billing:stripe-health');
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['healthy'])->toBeTrue()
        ->and($payload)->toHaveKeys(['environment', 'account_key', 'checked_at'])
        ->and(Artisan::output())->not->toContain('sk_test_')
        ->and(Artisan::output())->not->toContain('password');
});

it('emits unhealthy json and exit 1 for billing stripe health fail-closed', function (): void {
    app()->instance(StripeApiClient::class, new class extends StripeApiClient
    {
        public function retrieveAccount(StripeAccount $account): array
        {
            throw new StripeException('Stripe health check failed.');
        }
    });

    $exit = Artisan::call('billing:stripe-health');
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['healthy'])->toBeFalse()
        ->and($payload['account_key'])->toBe('unknown');
});

it('emits healthy json and exit 0 for billing sslcommerz health', function (): void {
    Http::fake([
        'sandbox.sslcommerz.com/*' => Http::response('ok', 200),
    ]);

    $exit = Artisan::call('billing:sslcommerz-health');
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload['healthy'])->toBeTrue()
        ->and($payload)->toHaveKeys(['environment', 'store_key', 'checked_at'])
        ->and(Artisan::output())->not->toContain('fixture-secret');
});

it('emits unhealthy json and exit 1 for billing sslcommerz health fail-closed', function (): void {
    Http::fake([
        'sandbox.sslcommerz.com/*' => Http::response('down', 500),
    ]);

    $exit = Artisan::call('billing:sslcommerz-health');
    $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['healthy'])->toBeFalse();
});
