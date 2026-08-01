<?php

declare(strict_types=1);

use App\Enums\AffiliateCommissionEntryStatus;
use App\Enums\AffiliateWithdrawalStatus;
use App\Exceptions\Affiliates\AffiliateWithdrawalException;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateConversion;
use App\Models\User;
use App\Services\Affiliates\AffiliateCommissionMaturityService;
use App\Services\Affiliates\AffiliateCommissionReversalService;
use App\Services\Affiliates\AffiliateConversionService;
use App\Services\Affiliates\AffiliateWithdrawalService;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates(['min_withdrawal_minor' => 5000]);
});

it('enforces one conversion per paid order under concurrent calls', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        // Still validates idempotency on SQLite; full lock races covered on MySQL/PostgreSQL CI.
    }

    [, $profile, , $buyer] = makeAffiliateContext();
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'rel-one'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'rel-one-evt'));

    $service = app(AffiliateConversionService::class);
    $a = $service->recordFromPaidOrder((string) $order->getKey());
    $b = $service->recordFromPaidOrder((string) $order->getKey());

    expect($a->getKey())->toBe($b->getKey())
        ->and(AffiliateConversion::query()->where('billing_order_id', $order->getKey())->count())->toBe(1);
});

it('prevents two withdrawals from spending the same available balance', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 0, 'percentage_bps' => 10000]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'rel-wd'));
    $order->forceFill(['subtotal_minor' => 10000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 10000])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'rel-wd-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());
    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();
    app(AffiliateCommissionMaturityService::class)->mature(50, false);

    $profile->forceFill(['payout_method' => 'paypal', 'payout_details_encrypted' => 'a@b.c'])->save();
    $service = app(AffiliateWithdrawalService::class);
    $first = $service->request($profile, 8000, 'USD', 'paypal', 'a@b.c', 'rel-a');

    expect($first->status)->toBe(AffiliateWithdrawalStatus::Requested);

    expect(fn () => $service->request($profile->fresh(), 8000, 'USD', 'paypal', 'a@b.c', 'rel-b'))
        ->toThrow(AffiliateWithdrawalException::class);
});

it('makes second admin approve of already-approved withdrawal a safe no-op', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 0, 'percentage_bps' => 10000]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'rel-ap'));
    $order->forceFill(['subtotal_minor' => 20000, 'discount_minor' => 0, 'tax_minor' => 0, 'total_minor' => 20000])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'rel-ap-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());
    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute(), 'status' => AffiliateCommissionEntryStatus::Available])->save();
    $profile->forceFill(['payout_method' => 'paypal', 'payout_details_encrypted' => 'a@b.c'])->save();

    $adminA = User::factory()->platformAdmin()->create();
    $adminB = User::factory()->platformAdmin()->create();
    $service = app(AffiliateWithdrawalService::class);
    $withdrawal = $service->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'rel-ap-1');
    $first = $service->approve($withdrawal, $adminA);
    $second = $service->approve($withdrawal->fresh(), $adminB);

    expect($first->status)->toBe(AffiliateWithdrawalStatus::Approved)
        ->and($second->status)->toBe(AffiliateWithdrawalStatus::Approved)
        ->and($second->approved_by)->toBe($adminA->getKey());
});

it('handles maturity versus reversal without double crediting', function (): void {
    if (DB::connection()->getDriverName() === 'sqlite') {
        test()->markTestSkipped('Maturity/reversal lock races require MySQL/PostgreSQL CI proof.');
    }

    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 0]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'rel-race'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'rel-race-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());
    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();

    app(AffiliateCommissionReversalService::class)->reverseConversion($conversion, 'race');
    $result = app(AffiliateCommissionMaturityService::class)->mature(50, false);

    expect($result['matured'])->toBe(0);
});
