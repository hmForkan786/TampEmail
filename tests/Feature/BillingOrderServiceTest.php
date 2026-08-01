<?php

use App\DTOs\Billing\CreateBillingOrderData;
use App\Enums\BillingCycle;
use App\Enums\BillingOrderStatus;
use App\Enums\BillingOrderType;
use App\Exceptions\Billing\BillingOrderConflictException;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;
use App\Models\BillingOrder;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\StateMachines\BillingOrderStateMachine;
use Database\Seeders\CommercialPlanFeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('creates an order with snapshotted minor-unit totals', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));

    expect($order->status)->toBe(BillingOrderStatus::Pending)
        ->and($order->total_minor)->toBe(900)
        ->and($order->currency)->toBe('USD')
        ->and($order->metadata['plan_slug'])->toBe('premium')
        ->and($order->metadata['price_snapshot_decimal'])->toBe('9.00');
});

it('reuses pending orders idempotently but rejects paid duplicates', function (): void {
    [$user, $plan] = billingPremiumContext();
    $service = app(BillingOrderService::class);
    $data = billingOrderData($user, $plan, 'dup-key');

    $first = $service->create($data);
    $second = $service->create($data);
    expect($second->getKey())->toBe($first->getKey());

    $service->transition($first, BillingOrderStatus::Processing);
    $service->transition($first->fresh(), BillingOrderStatus::Paid, ['paid_at' => now()]);

    expect(fn () => $service->create($data))->toThrow(BillingOrderConflictException::class);
});

it('keeps totals unchanged when plan price changes later', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $plan->update(['price_monthly' => '19.00']);

    expect(BillingOrder::query()->find($order->getKey())?->total_minor)->toBe(900);
});

it('rejects invalid state transitions centrally', function (): void {
    expect(fn () => app(BillingOrderStateMachine::class)->assertCanTransition(
        BillingOrderStatus::Paid,
        BillingOrderStatus::Pending,
    ))->toThrow(InvalidBillingStateTransitionException::class);
});

it('rejects checkout for free plans', function (): void {
    app(CommercialPlanFeatureSeeder::class)->run();
    $user = User::factory()->create();
    $plan = Plan::query()->where('slug', 'free')->firstOrFail();

    expect(fn () => app(BillingOrderService::class)->create(new CreateBillingOrderData(
        userId: (string) $user->getKey(),
        planId: (string) $plan->getKey(),
        type: BillingOrderType::Purchase,
        billingCycle: BillingCycle::Monthly,
        idempotencyKey: 'free-order',
    )))->toThrow(BillingOrderConflictException::class);
});
