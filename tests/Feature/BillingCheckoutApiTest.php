<?php

declare(strict_types=1);

use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('api.key_hash_secret', 'billing-checkout-test-secret');
});

it('exposes owner-scoped checkout lifecycle without activating a subscription', function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    ensureFreeCommercialUser($user);
    $token = issueCommercialApiKey($user, ['outbound_messages:read']);
    $plan = Plan::query()->where('slug', 'premium')->sole();

    $created = $this->withToken($token)->postJson('/api/v1/billing/checkout', [
        'plan_id' => $plan->getKey(),
        'gateway' => 'fake',
        'idempotency_key' => 'api-checkout-001',
        'success_url' => '/billing/success',
        'cancel_url' => '/billing/cancel',
    ])->assertCreated()
        ->assertJsonPath('data.total_minor', 900)
        ->assertJsonMissingPath('data.provider_secret');

    $orderId = $created->json('data.order_id');
    $this->withToken($token)->getJson("/api/v1/billing/orders/{$orderId}")
        ->assertOk()->assertJsonPath('data.id', $orderId);
    $this->withToken($token)->postJson("/api/v1/billing/orders/{$orderId}/resume")
        ->assertOk()->assertJsonPath('data.order_id', $orderId);
    $this->withToken($token)->postJson("/api/v1/billing/orders/{$orderId}/cancel")
        ->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('does not disclose another users order', function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
    $owner = User::factory()->create();
    $other = User::factory()->create();
    ensureFreeCommercialUser($owner);
    ensureFreeCommercialUser($other);
    $ownerToken = issueCommercialApiKey($owner, ['outbound_messages:read']);
    $otherToken = issueCommercialApiKey($other, ['outbound_messages:read']);
    $plan = Plan::query()->where('slug', 'premium')->sole();

    $orderId = $this->withToken($ownerToken)->postJson('/api/v1/billing/checkout', [
        'plan_id' => $plan->getKey(), 'gateway' => 'fake', 'idempotency_key' => 'owner-order-001',
        'success_url' => '/success', 'cancel_url' => '/cancel',
    ])->json('data.order_id');

    expect(BillingOrder::query()->whereKey($orderId)->exists())->toBeTrue();
    $this->withToken($otherToken)->getJson("/api/v1/billing/orders/{$orderId}")->assertNotFound();
});

it('synchronizes an owned processing order and exposes only safe payment projection', function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    ensureFreeCommercialUser($user);
    $token = issueCommercialApiKey($user, ['outbound_messages:read']);
    $plan = Plan::query()->where('slug', 'premium')->sole();
    $orderId = $this->withToken($token)->postJson('/api/v1/billing/checkout', [
        'plan_id' => $plan->getKey(), 'gateway' => 'fake', 'idempotency_key' => 'sync-owner-001',
        'success_url' => '/success', 'cancel_url' => '/cancel',
    ])->json('data.order_id');
    BillingOrder::query()->whereKey($orderId)->update(['provider_reference' => 'fake_success_owner_sync']);

    $this->withToken($token)->postJson("/api/v1/billing/orders/{$orderId}/sync")
        ->assertOk()->assertJsonPath('data.payment_status', 'succeeded');
    $this->withToken($token)->getJson("/api/v1/billing/orders/{$orderId}")
        ->assertOk()->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.order_status', 'paid')
        ->assertJsonMissingPath('data.provider_event_id')
        ->assertJsonMissingPath('data.provider_fee');
});
