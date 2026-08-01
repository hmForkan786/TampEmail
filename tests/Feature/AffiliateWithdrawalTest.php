<?php

declare(strict_types=1);

use App\Enums\AffiliateCommissionEntryType;
use App\Enums\AffiliateWithdrawalStatus;
use App\Exceptions\Affiliates\AffiliateWithdrawalException;
use App\Models\AffiliateCommissionEntry;
use App\Models\AffiliateProfile;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\Affiliates\AffiliateBalanceService;
use App\Services\Affiliates\AffiliateCommissionMaturityService;
use App\Services\Affiliates\AffiliateCommissionReversalService;
use App\Services\Affiliates\AffiliateConversionService;
use App\Services\Affiliates\AffiliateRegistrationService;
use App\Services\Affiliates\AffiliateWithdrawalService;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates(['min_withdrawal_minor' => 5000]);
});

/**
 * @return array{0: AffiliateProfile, 1: AffiliateWithdrawal, 2: User}
 */
function fundedAffiliateReadyToWithdraw(int $commissionMinor = 10000): array
{
    [, $profile, , $buyer] = makeAffiliateContext(['commission_hold_days' => 0, 'percentage_bps' => 10000]);
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'wd-'.uniqid()));
    // Ensure commission large enough: set order subtotal high and 100% bps
    $order->forceFill([
        'subtotal_minor' => $commissionMinor,
        'discount_minor' => 0,
        'tax_minor' => 0,
        'total_minor' => $commissionMinor,
    ])->save();
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'wd-evt-'.uniqid()));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();
    app(AffiliateCommissionMaturityService::class)->mature(100, false);

    $profile->forceFill([
        'payout_method' => 'paypal',
        'payout_details_encrypted' => 'affiliate@paypal.test',
    ])->save();

    return [$profile->fresh(), $conversion, $buyer];
}

it('requests a withdrawal and holds available balance', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $balance = app(AffiliateBalanceService::class)->project($profile, 'USD');
    expect($balance['net_available'])->toBeGreaterThanOrEqual(5000);

    $withdrawal = app(AffiliateWithdrawalService::class)->request(
        $profile,
        5000,
        'USD',
        'paypal',
        'affiliate@paypal.test',
        'idem-1',
    );

    expect($withdrawal->status)->toBe(AffiliateWithdrawalStatus::Requested)
        ->and(AffiliateCommissionEntry::query()->where('withdrawal_id', $withdrawal->getKey())->where('entry_type', AffiliateCommissionEntryType::WithdrawalHold)->count())->toBe(1);

    $after = app(AffiliateBalanceService::class)->project($profile->fresh(), 'USD');
    expect($after['held'])->toBe(5000)
        ->and($after['net_available'])->toBe($balance['net_available'] - 5000);
});

it('rejects below minimum and above available', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);

    expect(fn () => app(AffiliateWithdrawalService::class)->request($profile, 100, 'USD', 'paypal', 'a@b.c', 'low'))
        ->toThrow(AffiliateWithdrawalException::class);

    $available = app(AffiliateBalanceService::class)->project($profile, 'USD')['net_available'];

    expect(fn () => app(AffiliateWithdrawalService::class)->request($profile, $available + 1, 'USD', 'paypal', 'a@b.c', 'high'))
        ->toThrow(AffiliateWithdrawalException::class);
});

it('is idempotent on withdrawal request key', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $service = app(AffiliateWithdrawalService::class);
    $first = $service->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'same-key');
    $second = $service->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'same-key');

    expect($second->getKey())->toBe($first->getKey());
});

it('hides encrypted payout details from serialization', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $withdrawal = app(AffiliateWithdrawalService::class)->request($profile, 5000, 'USD', 'paypal', 'secret-paypal', 'hide-1');

    expect($withdrawal->toArray())->not->toHaveKey('payout_details_snapshot_encrypted')
        ->and($profile->toArray())->not->toHaveKey('payout_details_encrypted');
});

it('reviews approves rejects and releases hold', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $admin = User::factory()->platformAdmin()->create();
    $service = app(AffiliateWithdrawalService::class);
    $withdrawal = $service->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'flow-1');

    $beforeReject = app(AffiliateBalanceService::class)->project($profile, 'USD');
    $service->startReview($withdrawal, $admin);
    $service->reject($withdrawal->fresh(), $admin, 'incomplete details');

    expect($withdrawal->fresh()->status)->toBe(AffiliateWithdrawalStatus::Rejected);

    $after = app(AffiliateBalanceService::class)->project($profile->fresh(), 'USD');
    expect($after['held'])->toBe(0)
        ->and($after['net_available'])->toBeGreaterThanOrEqual($beforeReject['net_available']);
});

it('marks processing and paid with external reference exactly once', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $admin = User::factory()->platformAdmin()->create();
    $service = app(AffiliateWithdrawalService::class);
    $withdrawal = $service->request($profile, 5000, 'USD', 'paypal', 'a@b.c', 'paid-1');
    $service->approve($withdrawal, $admin);
    $service->markProcessing($withdrawal->fresh(), $admin);
    $paid = $service->markPaid($withdrawal->fresh(), $admin, 'PAYOUT-REF-1');
    $again = $service->markPaid($paid->fresh(), $admin, 'PAYOUT-REF-1');

    expect($paid->status)->toBe(AffiliateWithdrawalStatus::Paid)
        ->and($again->status)->toBe(AffiliateWithdrawalStatus::Paid)
        ->and(AffiliateCommissionEntry::query()->where('withdrawal_id', $paid->getKey())->where('entry_type', AffiliateCommissionEntryType::Payout)->count())->toBe(1);

    expect(fn () => $service->markPaid($paid->fresh(), $admin, ''))
        ->toThrow(AffiliateWithdrawalException::class);
});

it('blocks withdrawals for suspended affiliates', function (): void {
    [$profile] = fundedAffiliateReadyToWithdraw(20000);
    $admin = User::factory()->platformAdmin()->create();
    app(AffiliateRegistrationService::class)->suspend($profile, $admin, 'fraud');

    expect(fn () => app(AffiliateWithdrawalService::class)->request($profile->fresh(), 5000, 'USD', 'paypal', 'a@b.c', 'susp'))
        ->toThrow(AffiliateWithdrawalException::class);
});

it('blocks withdrawal when net available is zero after reversal', function (): void {
    [$profile, $conversion] = fundedAffiliateReadyToWithdraw(20000);
    app(AffiliateCommissionReversalService::class)->reverseConversion($conversion, 'chargeback');

    $balance = app(AffiliateBalanceService::class)->project($profile->fresh(), 'USD');
    expect($balance['net_available'])->toBe(0);

    expect(fn () => app(AffiliateWithdrawalService::class)->request($profile->fresh(), 5000, 'USD', 'paypal', 'a@b.c', 'neg'))
        ->toThrow(AffiliateWithdrawalException::class);
});
