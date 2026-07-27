<?php

use App\Enums\BillingCycle;
use App\Enums\ResetPeriod;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionLifecycleConflictException;
use App\Models\AuditLog;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionUsage;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use App\Services\Subscription\SubscriptionLifecycleService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lifecycleFixture(SubscriptionStatus $status = SubscriptionStatus::Cancelled): array
{
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->firstOrFail();
    $subscription = Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => $status, 'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(), 'auto_renew' => true, 'price' => '9.00', 'currency' => 'USD']);

    return [$user, $plan, $subscription, app(SubscriptionLifecycleService::class)];
}

it('activates idempotently and rejects invalid plans dates and overlaps', function (): void {
    [$user, $plan, $subscription, $service] = lifecycleFixture();
    $starts = now();
    $ends = now()->addMonth();
    $service->activate($subscription, $starts, $ends);
    $service->activate($subscription, $starts, $ends);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(AuditLog::query()->where('action', 'subscription.activated')->count())->toBe(1);

    $other = Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Cancelled, 'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now(), 'ends_at' => now()->addMonth(), 'auto_renew' => true, 'price' => '9.00', 'currency' => 'USD']);
    expect(fn () => $service->activate($other, now(), now()->addWeek()))->toThrow(SubscriptionLifecycleConflictException::class);
    $plan->update(['is_active' => false]);
    expect(fn () => $service->activate($other, now(), now()->addWeek()))->toThrow(SubscriptionLifecycleConflictException::class);
    expect(fn () => $service->activate($other, now(), now()->subSecond()))->toThrow(SubscriptionLifecycleConflictException::class);
});

it('supports immediate and period-end cancellation with Free fallback and no duplicate audit', function (): void {
    [$user, , $subscription, $service] = lifecycleFixture();
    $service->activate($subscription, now(), now()->addMonth());
    $service->cancelAtPeriodEnd($subscription);
    $service->cancelAtPeriodEnd($subscription);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->fresh()->cancel_at_period_end)->toBeTrue()
        ->and(app(EntitlementService::class)->effectivePlan($user)?->slug)->toBe('premium')
        ->and(AuditLog::query()->where('action', 'subscription.cancel_requested')->count())->toBe(1);

    $service->cancelImmediately($subscription);
    $service->cancelImmediately($subscription);
    expect($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($subscription->fresh()->auto_renew)->toBeFalse()
        ->and(app(EntitlementService::class)->effectivePlan($user)?->slug)->toBe('free')
        ->and(AuditLog::query()->where('action', 'subscription.cancelled')->count())->toBe(1);
});

it('preserves usage history during cancellation', function (): void {
    [, , $subscription, $service] = lifecycleFixture();
    $feature = Feature::query()->where('key', 'outbound_messages_per_period')->firstOrFail();
    SubscriptionUsage::query()->create(['subscription_id' => $subscription->id, 'feature_id' => $feature->id, 'used_value' => 5, 'limit_value' => 1000, 'reset_period' => ResetPeriod::Monthly, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth()]);
    $service->activate($subscription, now(), now()->addMonth());
    $service->cancelImmediately($subscription);
    expect(SubscriptionUsage::query()->where('subscription_id', $subscription->id)->value('used_value'))->toBe(5);
});
