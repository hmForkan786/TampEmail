<?php

use App\DTOs\Billing\CreateBillingOrderData;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Enums\SubscriptionStatus;
use App\Events\SubscriptionLifecycleEvent;
use App\Jobs\Billing\CreateRenewalOrdersJob;
use App\Jobs\Billing\ExpireSubscriptionsJob;
use App\Jobs\Billing\StartGracePeriodJob;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaidSubscriptionActivationService;
use App\Services\Entitlement\EntitlementService;
use App\Services\Subscription\SubscriptionRenewalScheduler;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function renewalLifecycleSubscription(SubscriptionStatus $status = SubscriptionStatus::Active, array $attributes = []): Subscription
{
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->firstOrFail();

    return Subscription::query()->create(array_merge([
        'user_id' => $user->id,
        'plan_id' => $plan->id,
        'status' => $status,
        'billing_cycle' => BillingCycle::Monthly,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->addDays(2),
        'auto_renew' => true,
        'price' => '9.00',
        'currency' => 'USD',
    ], $attributes));
}

beforeEach(function (): void {
    config([
        'billing.lifecycle.grace_days' => 7,
        'billing.lifecycle.renewal_lead_days' => 3,
        'billing.lifecycle.trial_days' => 14,
        'billing.lifecycle.batch_size' => 100,
    ]);
});

it('creates one renewal order and marks renewal due idempotently', function (): void {
    Event::fake([SubscriptionLifecycleEvent::class]);
    $subscription = renewalLifecycleSubscription();
    $scheduler = app(SubscriptionRenewalScheduler::class);

    $first = $scheduler->createRenewalOrders();
    $second = $scheduler->createRenewalOrders();
    expect($first['processed'])->toBe(1)
        ->and($second['processed'])->toBe(0)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::RenewalDue)
        ->and(app(EntitlementService::class)->effectivePlan($subscription->user)?->slug)->toBe('premium')
        ->and(BillingOrder::query()->where('subscription_id', $subscription->id)->where('type', BillingOrderType::Renewal)->count())->toBe(1);
    Event::assertDispatched(SubscriptionLifecycleEvent::class, fn ($event) => $event->name === 'renewal_due');
});

it('enters grace, emits one daily reminder, and resolves limited free entitlements', function (): void {
    Event::fake([SubscriptionLifecycleEvent::class]);
    $subscription = renewalLifecycleSubscription(SubscriptionStatus::RenewalDue, ['ends_at' => now()->subMinute()]);
    $scheduler = app(SubscriptionRenewalScheduler::class);

    $scheduler->startGracePeriods();
    $scheduler->startGracePeriods();
    $fresh = $subscription->fresh();

    expect($fresh->status)->toBe(SubscriptionStatus::Grace)
        ->and($fresh->metadata['grace_ends_at'])->not->toBeNull()
        ->and(app(EntitlementService::class)->effectivePlan($fresh->user)?->slug)->toBe('free');
    Event::assertDispatchedTimes(SubscriptionLifecycleEvent::class, 2);
});

it('expires trials and grace subscriptions without deleting subscription data', function (): void {
    Event::fake([SubscriptionLifecycleEvent::class]);
    $trial = renewalLifecycleSubscription(SubscriptionStatus::Trial, [
        'trial_ends_at' => now()->subMinute(),
        'ends_at' => now()->subMinute(),
    ]);
    $grace = renewalLifecycleSubscription(SubscriptionStatus::Grace, [
        'ends_at' => now()->subDays(8),
        'metadata' => ['grace_ends_at' => now()->subMinute()->toIso8601String()],
    ]);

    (new ExpireSubscriptionsJob)->handle(app(SubscriptionRenewalScheduler::class));
    (new ExpireSubscriptionsJob)->handle(app(SubscriptionRenewalScheduler::class));

    expect($trial->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($grace->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and(Subscription::query()->whereKey($trial->id)->exists())->toBeTrue()
        ->and(Subscription::query()->whereKey($grace->id)->exists())->toBeTrue();
});

it('recovers grace and expired subscriptions only through paid-order activation', function (SubscriptionStatus $status): void {
    Event::fake([SubscriptionLifecycleEvent::class]);
    $subscription = renewalLifecycleSubscription($status, [
        'ends_at' => now()->subDay(),
        'metadata' => $status === SubscriptionStatus::Grace
            ? ['grace_ends_at' => now()->addDays(6)->toIso8601String()]
            : null,
    ]);
    $order = app(BillingOrderService::class)->create(new CreateBillingOrderData(
        userId: $subscription->user_id,
        planId: $subscription->plan_id,
        type: BillingOrderType::Renewal,
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: "test-recovery:{$subscription->id}:{$status->value}",
        subscriptionId: (string) $subscription->id,
        provider: 'fake',
    ));
    $order->forceFill(['status' => BillingOrderStatus::Paid, 'paid_at' => now()])->save();

    app(PaidSubscriptionActivationService::class)->activateFromPaidOrder((string) $order->id);
    app(PaidSubscriptionActivationService::class)->activateFromPaidOrder((string) $order->id);

    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->ends_at?->isFuture())->toBeTrue()
        ->and(PaymentTransaction::query()->count())->toBe(0);
})->with([SubscriptionStatus::Grace, SubscriptionStatus::Expired]);

it('does not automatically renew cancelled subscriptions and fails closed for invalid config', function (): void {
    $cancelled = renewalLifecycleSubscription(SubscriptionStatus::Cancelled);
    (new CreateRenewalOrdersJob)->handle(app(SubscriptionRenewalScheduler::class));
    expect(BillingOrder::query()->where('subscription_id', $cancelled->id)->exists())->toBeFalse();

    config(['billing.lifecycle.grace_days' => 0]);
    expect(fn () => (new StartGracePeriodJob)->handle(app(SubscriptionRenewalScheduler::class)))
        ->toThrow(RuntimeException::class);
});
