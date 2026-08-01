<?php

declare(strict_types=1);

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\ProviderPaymentStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Jobs\Billing\ActivatePaidSubscriptionJob;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaymentProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

function paymentEvent($order, string $eventId, ProviderPaymentStatus $status, PaymentTransactionType $type, int $amount): VerifiedProviderEvent
{
    return new VerifiedProviderEvent(
        provider: 'fake', providerEventId: $eventId, eventType: 'payment.'.$status->value,
        providerTransactionId: 'fake_tx_'.$eventId, billingOrderId: (string) $order->getKey(),
        amountMinor: $amount, currency: $order->currency, transactionType: $type,
        succeeded: $status->isFinancialSuccess(), paymentStatus: $status,
    );
}

it('records authorization without payment or activation then pays on full capture', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $processing = app(PaymentProcessingService::class);

    $authorized = $processing->processVerifiedPayment(paymentEvent($order, 'auth-1', ProviderPaymentStatus::Authorized, PaymentTransactionType::Authorization, $order->total_minor));
    expect($authorized->status)->toBe(BillingOrderStatus::Processing);
    Queue::assertNotPushed(ActivatePaidSubscriptionJob::class);

    $paid = $processing->processVerifiedPayment(paymentEvent($order, 'capture-1', ProviderPaymentStatus::Captured, PaymentTransactionType::Capture, $order->total_minor));
    expect($paid->status)->toBe(BillingOrderStatus::Paid)
        ->and(PaymentTransaction::query()->where('status', PaymentTransactionStatus::Succeeded)->count())->toBe(2);
    Queue::assertPushed(ActivatePaidSubscriptionJob::class, 1);
});

it('supports partial capture and rejects over-capture', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $processing = app(PaymentProcessingService::class);
    $processing->processVerifiedPayment(paymentEvent($order, 'auth-partial', ProviderPaymentStatus::Authorized, PaymentTransactionType::Authorization, $order->total_minor));
    $processing->processVerifiedPayment(paymentEvent($order, 'capture-partial', ProviderPaymentStatus::Captured, PaymentTransactionType::Capture, 400));

    expect($order->fresh()->status)->toBe(BillingOrderStatus::Processing)
        ->and(fn () => $processing->processVerifiedPayment(paymentEvent($order, 'capture-over', ProviderPaymentStatus::Captured, PaymentTransactionType::Capture, 600)))
        ->toThrow(PaymentVerificationException::class);
});

it('does not downgrade paid orders on late failure pending or expiry', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $processing = app(PaymentProcessingService::class);
    $processing->processVerifiedPayment(paymentEvent($order, 'sale-first', ProviderPaymentStatus::Succeeded, PaymentTransactionType::Sale, $order->total_minor));

    foreach ([ProviderPaymentStatus::Failed, ProviderPaymentStatus::Pending, ProviderPaymentStatus::Expired] as $status) {
        $processing->processVerifiedPayment(paymentEvent($order, 'late-'.$status->value, $status, PaymentTransactionType::Sale, $order->total_minor));
    }
    expect($order->fresh()->status)->toBe(BillingOrderStatus::Paid);
});
