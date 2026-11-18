<?php

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogWriter;
use App\Services\Entitlement\EntitlementService;
use App\Services\Subscription\ExpireSubscriptionsService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function expiringSubscription(SubscriptionStatus $status, $endsAt, $trialEndsAt = null): Subscription
{
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'premium')->firstOrFail();

    return Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => $status, 'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now()->subMonth(), 'trial_ends_at' => $trialEndsAt, 'ends_at' => $endsAt, 'auto_renew' => true, 'price' => '9.00', 'currency' => 'USD']);
}

beforeEach(fn () => app(CommercialPlanFeatureSeeder::class)->run());

it('dry-runs then expires ended active and trial subscriptions idempotently', function (): void {
    $active = expiringSubscription(SubscriptionStatus::Active, now());
    $trial = expiringSubscription(SubscriptionStatus::Trial, now()->addDay(), now());
    $future = expiringSubscription(SubscriptionStatus::Active, now()->addDay());

    $this->artisan('subscriptions:expire', ['--dry-run' => true, '--batch' => 1])->assertSuccessful();
    expect($active->fresh()->status)->toBe(SubscriptionStatus::Active);

    $this->artisan('subscriptions:expire', ['--batch' => 1])->assertSuccessful();
    $this->artisan('subscriptions:expire', ['--batch' => 1])->assertSuccessful();
    expect($active->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($trial->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($future->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(AuditLog::query()->where('action', 'subscription.expired')->count())->toBe(2)
        ->and(Subscription::query()->where('plan_id', Plan::query()->where('slug', 'free')->value('id'))->count())->toBe(0)
        ->and(app(EntitlementService::class)->effectivePlan($active->user)?->slug)->toBe('free');
});

it('registers bounded expiry every five minutes without overlap', function (): void {
    Artisan::call('schedule:list');
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'subscriptions:expire --batch=100'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('rolls back and reports one failure when audit persistence fails', function (): void {
    $subscription = expiringSubscription(SubscriptionStatus::Active, now());
    $audit = Mockery::mock(AuditLogWriter::class);
    $audit->shouldReceive('write')->once()->andThrow(new RuntimeException('audit unavailable'));

    $result = (new ExpireSubscriptionsService($audit))->process(false, 100);

    expect($result['failed'])->toBe(1)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
});
