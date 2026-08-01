<?php

declare(strict_types=1);

use App\Models\AffiliateCommissionEntry;
use App\Models\AuditLog;
use App\Models\OutboundNotification;
use App\Services\Affiliates\AffiliateCommissionMaturityService;
use App\Services\Affiliates\AffiliateConversionService;
use App\Services\Affiliates\AffiliateNotificationService;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';
require_once __DIR__.'/../Support/affiliate_helpers.php';

beforeEach(function (): void {
    enableAffiliates(['commission_hold_days' => 0]);
});

it('notifies on commission earned and deduplicates', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext();
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'notif-1'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'notif-1-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    expect(AuditLog::query()->where('action', 'affiliate.commission_created')->count())->toBeGreaterThanOrEqual(1);

    $before = OutboundNotification::query()->where('user_id', $profile->user_id)->count();
    $notifications = app(AffiliateNotificationService::class);
    $notifications->notify($profile->user, 'affiliate.commission_earned', [
        'amount_minor' => $conversion->commission_amount_minor,
    ], 'dedupe-commission:'.$conversion->getKey());
    $notifications->notify($profile->user, 'affiliate.commission_earned', [
        'amount_minor' => $conversion->commission_amount_minor,
    ], 'dedupe-commission:'.$conversion->getKey());

    expect(OutboundNotification::query()->where('user_id', $profile->user_id)->where('idempotency_key', 'dedupe-commission:'.$conversion->getKey())->count())->toBe(1)
        ->and(OutboundNotification::query()->where('user_id', $profile->user_id)->count())->toBeGreaterThanOrEqual($before);
});

it('notifies when commission matures', function (): void {
    [, $profile, , $buyer] = makeAffiliateContext();
    linkBuyerAttribution($profile, $buyer);
    [, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($buyer, $plan, 'notif-mat'));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(verifiedFromOrder($order, eventId: 'notif-mat-evt'));
    $conversion = app(AffiliateConversionService::class)->recordFromPaidOrder((string) $order->getKey());

    $entry = AffiliateCommissionEntry::query()->where('conversion_id', $conversion->getKey())->firstOrFail();
    $entry->forceFill(['available_at' => now()->subMinute()])->save();
    app(AffiliateCommissionMaturityService::class)->mature(50, false);

    expect(AuditLog::query()->where('action', 'affiliate.commission_matured')->count())->toBeGreaterThanOrEqual(1);
});
