<?php

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Models\AuditLog;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Models\Subscription;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('processes verified payment success and records ledger plus audit', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));

    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        verifiedFromOrder($order, succeeded: true, eventId: 'evt-success'),
    );

    $fresh = $order->fresh();
    expect($fresh->status)->toBe(BillingOrderStatus::Paid)
        ->and(PaymentTransaction::query()->where('billing_order_id', $order->getKey())->where('status', PaymentTransactionStatus::Succeeded)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.payment.succeeded')->count())->toBe(1);

    Queue::assertPushed(ActivatePaidSubscriptionJob::class);
});

it('fail-closes amount and currency mismatches', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'amt-order'));
    $orderTwo = app(BillingOrderService::class)->create(billingOrderData($user, $plan, 'cur-order'));

    expect(fn () => app(PaymentProcessingService::class)->recordSuccessfulPayment(
        verifiedFromOrder($order, amountMinor: 1, eventId: 'evt-amt'),
    ))->toThrow(PaymentVerificationException::class);

    expect(fn () => app(PaymentProcessingService::class)->recordSuccessfulPayment(
        verifiedFromOrder($orderTwo, currency: 'EUR', eventId: 'evt-cur'),
    ))->toThrow(PaymentVerificationException::class);

    expect(AuditLog::query()->where('action', 'billing.payment.amount_mismatch')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'billing.payment.currency_mismatch')->count())->toBe(1);
});

it('does not mark an already paid order twice', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $processing = app(PaymentProcessingService::class);
    $verified = verifiedFromOrder($order, eventId: 'evt-dup');

    $processing->recordSuccessfulPayment($verified);
    $processing->recordSuccessfulPayment($verified);

    expect(PaymentTransaction::query()->where('billing_order_id', $order->getKey())->where('status', PaymentTransactionStatus::Succeeded)->count())->toBe(1);
});

it('creates checkout sessions without activating subscriptions', function (): void {
    [$user, $plan] = billingPremiumContext();
    $result = app(CheckoutService::class)->start(
        billingOrderData($user, $plan, 'checkout-1'),
        'https://app.test/success',
        'https://app.test/cancel',
    );

    expect($result['order']->status)->toBe(BillingOrderStatus::Processing)
        ->and($result['checkout']->checkoutUrl)->toContain('checkout.example.test')
        ->and(Subscription::query()->where('user_id', $user->getKey())->count())->toBe(0)
        ->and(PaymentTransaction::query()->where('status', PaymentTransactionStatus::Pending)->count())->toBe(1);
});

function verifiedFromOrder(BillingOrder $order, bool $succeeded = true, ?int $amountMinor = null, ?string $currency = null, string $eventId = 'evt'): VerifiedProviderEvent
{
    return new VerifiedProviderEvent(
        provider: 'fake',
        providerEventId: $eventId,
        eventType: 'payment.updated',
        providerTransactionId: 'fake_tx_'.$eventId,
        billingOrderId: (string) $order->getKey(),
        amountMinor: $amountMinor ?? $order->total_minor,
        currency: $currency ?? $order->currency,
        transactionType: PaymentTransactionType::Sale,
        succeeded: $succeeded,
    );
}
