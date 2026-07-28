<?php

declare(strict_types=1);

use App\DTOs\Billing\StartCheckoutData;
use App\Enums\BillingCheckoutSessionStatus;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderStatus;
use App\Exceptions\Billing\CheckoutException;
use App\Exceptions\Billing\UnknownPaymentProviderException;
use App\Models\AuditLog;
use App\Models\BillingCheckoutSession;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CheckoutExpiryService;
use App\Services\Billing\CheckoutRedirectPolicy;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\StateMachines\BillingCheckoutSessionStateMachine;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function checkoutData(User $user, Plan $plan, string $key = 'checkout-key-001', array $overrides = []): StartCheckoutData
{
    return new StartCheckoutData(
        userId: (string) $user->getKey(),
        planId: (string) $plan->getKey(),
        gateway: $overrides['gateway'] ?? 'fake',
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: $key,
        successUrl: $overrides['success_url'] ?? '/billing/success',
        cancelUrl: $overrides['cancel_url'] ?? '/billing/cancel',
        clientReference: $overrides['client_reference'] ?? null,
    );
}

beforeEach(function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
});

it('normalizes first-party redirects and rejects open redirects', function (): void {
    $policy = app(CheckoutRedirectPolicy::class);

    expect($policy->normalize('/billing//success'))->toBe('/billing//success')
        ->and($policy->normalize('https://app.test/billing/success'))->toBe('https://app.test/billing/success');

    foreach (['//evil.test/x', 'javascript:alert(1)', 'data:text/html,x', 'file:///tmp/x', 'https://evil.test/x', 'https://user:pass@app.test/x'] as $url) {
        expect(fn () => $policy->normalize($url))->toThrow(CheckoutException::class);
    }
});

it('creates one server-priced order and session for an idempotent request', function (): void {
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $service = app(CheckoutService::class);

    $first = $service->startCheckout(checkoutData($user, $plan));
    $plan->forceFill(['price_monthly' => '99.00', 'currency' => 'BDT'])->save();
    $second = $service->startCheckout(checkoutData($user, $plan));

    expect($first['reused'])->toBeFalse()
        ->and($second['reused'])->toBeTrue()
        ->and($second['order']->getKey())->toBe($first['order']->getKey())
        ->and($first['order']->total_minor)->toBe(900)
        ->and($first['order']->currency)->toBe('USD')
        ->and(BillingOrder::query()->count())->toBe(1)
        ->and(BillingCheckoutSession::query()->count())->toBe(1)
        ->and(Subscription::query()->where('user_id', $user->getKey())->count())->toBe(0);
});

it('rejects idempotency key reuse with a different normalized payload', function (): void {
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $service = app(CheckoutService::class);

    $service->startCheckout(checkoutData($user, $plan, 'checkout-conflict'));

    expect(fn () => $service->startCheckout(checkoutData($user, $plan, 'checkout-conflict', [
        'cancel_url' => '/different',
    ])))->toThrow(CheckoutException::class, 'Idempotency conflict')
        ->and(AuditLog::query()->where('action', 'billing.checkout.idempotency_conflict')->exists())->toBeTrue();
});

it('fails closed for unavailable plans and gateways', function (): void {
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $plan->forceFill(['is_active' => false])->save();

    expect(fn () => app(CheckoutService::class)->startCheckout(checkoutData($user, $plan)))
        ->toThrow(CheckoutException::class);

    $plan->forceFill(['is_active' => true])->save();
    expect(fn () => app(CheckoutService::class)->startCheckout(checkoutData($user, $plan, 'unknown-gateway', ['gateway' => 'unknown'])))
        ->toThrow(UnknownPaymentProviderException::class);
});

it('resumes and cancels only eligible owner checkouts', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $service = app(CheckoutService::class);
    $created = $service->startCheckout(checkoutData($user, $plan, 'resume-cancel'));

    expect($service->resume($created['order'], (string) $user->getKey())['session']->getKey())
        ->toBe($created['session']->getKey())
        ->and(fn () => $service->resume($created['order'], (string) $other->getKey()))
        ->toThrow(CheckoutException::class);

    $cancelled = $service->cancel($created['order']->fresh(), (string) $user->getKey());
    expect($cancelled->status)->toBe(BillingOrderStatus::Cancelled)
        ->and($created['session']->fresh()->status)->toBe(BillingCheckoutSessionStatus::Cancelled);
});

it('expires unpaid checkouts idempotently', function (): void {
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $created = app(CheckoutService::class)->startCheckout(checkoutData($user, $plan, 'expire-checkout'));
    $created['order']->forceFill(['expires_at' => now()->subMinute()])->save();
    $created['session']->forceFill(['expires_at' => now()->subMinute()])->save();

    $expiry = app(CheckoutExpiryService::class);
    expect($expiry->expire(100))->toBe(1)
        ->and($expiry->expire(100))->toBe(0)
        ->and($created['order']->fresh()->status)->toBe(BillingOrderStatus::Expired)
        ->and($created['session']->fresh()->status)->toBe(BillingCheckoutSessionStatus::Expired);
});

it('rejects terminal checkout session transitions', function (): void {
    expect(fn () => app(BillingCheckoutSessionStateMachine::class)->assertCanTransition(
        BillingCheckoutSessionStatus::Completed,
        BillingCheckoutSessionStatus::Pending,
    ))->toThrow(CheckoutException::class);
});
