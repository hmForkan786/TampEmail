<?php

use App\DTOs\Billing\RecordPaymentTransactionData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\RefundResult;
use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Billing\BillingException;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\BillingRefundService;
use App\Services\Billing\PaidSubscriptionActivationService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('appends refund transactions without deleting capture history', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        new VerifiedProviderEvent(
            provider: 'fake',
            providerEventId: 'evt-ref-1',
            eventType: 'payment.succeeded',
            providerTransactionId: 'fake_tx_ref_1',
            billingOrderId: (string) $order->getKey(),
            amountMinor: $order->total_minor,
            currency: $order->currency,
            transactionType: PaymentTransactionType::Sale,
            succeeded: true,
        ),
    );

    app(PaidSubscriptionActivationService::class)->activateFromPaidOrder((string) $order->getKey());
    $refunds = app(BillingRefundService::class);

    $refunds->recordRefund($order->fresh(), new RefundPaymentData(
        provider: 'fake',
        billingOrderId: (string) $order->getKey(),
        providerTransactionId: 'fake_tx_ref_1',
        amountMinor: 400,
        currency: 'USD',
        idempotencyKey: 'refund-partial-1',
        partial: true,
    ), new RefundResult('fake_ref_1', 400, 'USD', true));

    expect($order->fresh()->status)->toBe(BillingOrderStatus::PartiallyRefunded)
        ->and(PaymentTransaction::query()->where('type', PaymentTransactionType::Sale)->count())->toBe(1)
        ->and(PaymentTransaction::query()->where('type', PaymentTransactionType::PartialRefund)->count())->toBe(1);
});

it('rejects cumulative refunds above captured totals', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        new VerifiedProviderEvent(
            provider: 'fake',
            providerEventId: 'evt-ref-2',
            eventType: 'payment.succeeded',
            providerTransactionId: 'fake_tx_ref_2',
            billingOrderId: (string) $order->getKey(),
            amountMinor: $order->total_minor,
            currency: $order->currency,
            transactionType: PaymentTransactionType::Sale,
            succeeded: true,
        ),
    );

    $service = app(BillingRefundService::class);
    expect(fn () => $service->recordRefund($order->fresh(), new RefundPaymentData(
        provider: 'fake',
        billingOrderId: (string) $order->getKey(),
        providerTransactionId: 'fake_tx_ref_2',
        amountMinor: $order->total_minor + 1,
        currency: 'USD',
        idempotencyKey: 'refund-too-much',
    ), new RefundResult('fake_ref_x', $order->total_minor + 1, 'USD', true)))->toThrow(BillingException::class);
});

it('records chargebacks append-only and cancels entitlement centrally', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        new VerifiedProviderEvent(
            provider: 'fake',
            providerEventId: 'evt-cb-1',
            eventType: 'payment.succeeded',
            providerTransactionId: 'fake_tx_cb_1',
            billingOrderId: (string) $order->getKey(),
            amountMinor: $order->total_minor,
            currency: $order->currency,
            transactionType: PaymentTransactionType::Sale,
            succeeded: true,
        ),
    );
    $subscription = app(PaidSubscriptionActivationService::class)->activateFromPaidOrder((string) $order->getKey());

    app(BillingRefundService::class)->recordChargeback($order->fresh(), new RecordPaymentTransactionData(
        billingOrderId: (string) $order->getKey(),
        userId: (string) $user->getKey(),
        provider: 'fake',
        type: PaymentTransactionType::Chargeback,
        status: PaymentTransactionStatus::Succeeded,
        amountMinor: $order->total_minor,
        currency: 'USD',
        providerTransactionId: 'fake_cb_1',
        idempotencyKey: 'chargeback-1',
    ));

    expect($order->fresh()->status)->toBe(BillingOrderStatus::ChargedBack)
        ->and(PaymentTransaction::query()->where('type', PaymentTransactionType::Chargeback)->count())->toBe(1)
        ->and($subscription->fresh()->status)->toBe(SubscriptionStatus::Cancelled);
});
