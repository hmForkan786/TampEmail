<?php

declare(strict_types=1);

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateCommissionType;
use App\Enums\AffiliateConversionStatus;
use App\Models\AffiliateCommissionEntry;
use App\Models\User;
use App\Services\Affiliates\AffiliateCommissionCalculator;
use App\Services\Affiliates\AffiliateCommissionMaturityService;
use App\Services\Affiliates\AffiliateCommissionReversalService;
use App\Services\Affiliates\AffiliateConversionService;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates();
});

it('calculates percentage commission with integer floor', function (): void {
    $plan = makePercentagePlan(['percentage_bps' => 1000]);
    [, $billingPlan] = billingPremiumContext();
    [$user] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $billingPlan, 'calc-pct'));
    // Force known amounts
    $order->forceFill([
        'subtotal_minor' => 10000,
        'discount_minor' => 1000,
        'tax_minor' => 500,
        'total_minor' => 9500,
    ])->save();

    // base = 9000; 10% = 900
    expect(app(AffiliateCommissionCalculator::class)->calculate($order->fresh(), $plan))->toBe(900);
});

it('enforces maximum commission cap and minimum order', function (): void {
    $capped = makePercentagePlan(['percentage_bps' => 5000, 'maximum_commission_minor' => 100]);
    $min = makePercentagePlan(['percentage_bps' => 1000, 'minimum_order_minor' => 1_000_000, 'name' => 'Min Plan']);
    [, $billingPlan] = billingPremiumContext();
    $user = User::factory()->create();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $billingPlan, 'calc-cap'));
    $order->forceFill([
        'subtotal_minor' => 10000,
        'discount_minor' => 0,
        'total_minor' => 10000,
    ])->save();

    expect(app(AffiliateCommissionCalculator::class)->calculate($order->fresh(), $capped))->toBe(100)
        ->and(app(AffiliateCommissionCalculator::class)->calculate($order->fresh(), $min))->toBe(0);
});

it('calculates fixed commission when currency matches', function (): void {
    $plan = makePercentagePlan([
        'name' => 'Fixed Plan',
        'commission_type' => AffiliateCommissionType::Fixed,
        'percentage_bps' => null,
        'fixed_amount_minor' => 2500,
        'currency' => 'USD',
    ]);
    [, $billingPlan] = billingPremiumContext();
    $user = User::factory()->create();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $billingPlan, 'calc-fixed'));

    expect(app(AffiliateCommissionCalculator::class)->calculate($order, $plan))->toBe(2500);
});

it('matures pending commissions idempotently and supports reversal', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 1]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'aff-mat'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'aff-mat-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();

    $maturity = app(AffiliateCommissionMaturityService::class);
    $first = $maturity->mature(100, false);
    $second = $maturity->mature(100, false);

    expect($first['matured'])->toBe(1)
        ->and($second['matured'])->toBe(0)
        ->and($entry->fresh()->status)->toBe(AffiliateCommissionEntryStatus::Available);

    $originalAmount = $entry->amount_minor;
    $reversal = app(AffiliateCommissionReversalService::class)->reverseConversion($conversion, 'chargeback');

    expect($reversal->amount_minor)->toBe(-$originalAmount)
        ->and($entry->fresh()->amount_minor)->toBe($originalAmount)
        ->and($conversion->fresh()->status)->toBe(AffiliateConversionStatus::Reversed);
});

it('snapshots plan terms immutably after conversion', function (): void {
    [, $profile, $affPlan, $buyer] = makeAffiliateContext(['percentage_bps' => 1000]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'aff-snap'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'aff-snap-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $snapshotBps = $conversion->commission_plan_snapshot['percentage_bps'];
    $affPlan->forceFill(['percentage_bps' => 500])->save();

    expect($conversion->fresh()->commission_plan_snapshot['percentage_bps'])->toBe($snapshotBps)
        ->and($snapshotBps)->toBe(1000);
});

it('does not mature when conversion is reversed', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 1]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'aff-nomature'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'aff-nomature-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();

    app(AffiliateCommissionReversalService::class)->reverseConversion($conversion, 'fraud');
    $result = app(AffiliateCommissionMaturityService::class)->mature(100, false);

    expect($result['matured'])->toBe(0)
        ->and($entry->fresh()->status)->toBe(AffiliateCommissionEntryStatus::Pending);
});
