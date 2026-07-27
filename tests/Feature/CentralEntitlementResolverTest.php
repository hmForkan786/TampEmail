<?php

use App\Enums\BillingCycle;
use App\Enums\SubscriptionStatus;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlement\EntitlementService;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function resolverFixture(): array
{
    app(CommercialPlanFeatureSeeder::class)->run();

    return [User::factory()->create(), Plan::query()->where('slug', 'premium')->firstOrFail()];
}

function resolverSubscription(User $user, Plan $plan, array $overrides = []): Subscription
{
    return Subscription::query()->create(array_merge(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => SubscriptionStatus::Active, 'billing_cycle' => BillingCycle::Monthly, 'starts_at' => now(), 'ends_at' => now()->addDay(), 'auto_renew' => true, 'price' => '9.00', 'currency' => 'USD'], $overrides));
}

it('falls back to Free for absent, expired, future, and inactive subscriptions or plans', function (): void {
    [$user, $premium] = resolverFixture();
    $service = app(EntitlementService::class);
    expect($service->effectivePlan($user)?->slug)->toBe('free');
    resolverSubscription($user, $premium, ['ends_at' => now()]);
    expect($service->effectivePlan($user)?->slug)->toBe('free');
    Subscription::query()->delete();
    resolverSubscription($user, $premium, ['starts_at' => now()->addSecond()]);
    expect($service->effectivePlan($user)?->slug)->toBe('free');
    Subscription::query()->delete();
    $premium->update(['is_active' => false]);
    resolverSubscription($user, $premium);
    expect($service->effectivePlan($user)?->slug)->toBe('free');
});

it('resolves valid Premium and parses boolean and numeric values fail-closed', function (): void {
    [$user, $premium] = resolverFixture();
    $service = app(EntitlementService::class);
    resolverSubscription($user, $premium);
    expect($service->effectivePlan($user)?->slug)->toBe('premium')->and($service->allows($user, 'send_email'))->toBeTrue()->and($service->limit($user, 'max_inboxes'))->toBe(25);
    $feature = Feature::query()->where('key', 'send_email')->firstOrFail();
    $premium->features()->updateExistingPivot($feature->id, ['feature_value' => ['enabled' => 'false']]);
    expect($service->allows($user, 'send_email'))->toBeFalse()->and($service->allows($user, 'missing.feature'))->toBeFalse();
    $feature = Feature::query()->where('key', 'max_inboxes')->firstOrFail();
    $premium->features()->updateExistingPivot($feature->id, ['feature_value' => ['limit' => -1]]);
    expect($service->limit($user, 'max_inboxes'))->toBe(0)->and($service->limit($user, 'missing.limit'))->toBe(0);
});
