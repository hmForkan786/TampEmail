<?php

use App\DTOs\Billing\VerifiedProviderEvent;
use App\Enums\BillingActivationStatus;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionType;
use App\Enums\SubscriptionStatus;
use App\Exceptions\SubscriptionLifecycleConflictException;
use App\Models\AuditLog;
use App\Models\Subscription;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\PaidSubscriptionActivationService;
use App\Services\Billing\PaymentProcessingService;
use App\Services\Entitlement\EntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('activates exactly one subscription for a paid order and supports retry', function (): void {
    Queue::fake();
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(PaymentProcessingService::class)->recordSuccessfulPayment(
        new VerifiedProviderEvent(
            provider: 'fake',
            providerEventId: 'evt-act-1',
            eventType: 'payment.succeeded',
            providerTransactionId: 'fake_tx_act_1',
            billingOrderId: (string) $order->getKey(),
            amountMinor: $order->total_minor,
            currency: $order->currency,
            transactionType: PaymentTransactionType::Sale,
            succeeded: true,
        ),
    );

    $activation = app(PaidSubscriptionActivationService::class);
    $first = $activation->activateFromPaidOrder((string) $order->getKey());
    $second = $activation->activateFromPaidOrder((string) $order->getKey());

    expect($second->getKey())->toBe($first->getKey())
        ->and(Subscription::query()->where('user_id', $user->getKey())->count())->toBe(1)
        ->and($first->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and(app(EntitlementService::class)->effectivePlan($user)?->slug)->toBe('premium')
        ->and($order->fresh()->metadata['activation_status'])->toBe(BillingActivationStatus::Succeeded->value)
        ->and(AuditLog::query()->where('action', 'billing.subscription.activated')->count())->toBe(1);
});

it('keeps payment recorded when activation fails and marks metadata for recovery', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(BillingOrderService::class)->transition($order, BillingOrderStatus::Processing);
    app(BillingOrderService::class)->transition($order->fresh(), BillingOrderStatus::Paid, ['paid_at' => now()]);

    $plan->update(['is_active' => false]);

    expect(fn () => app(PaidSubscriptionActivationService::class)->activateFromPaidOrder((string) $order->fresh()->getKey()))
        ->toThrow(SubscriptionLifecycleConflictException::class);

    expect($order->fresh()->status)->toBe(BillingOrderStatus::Paid)
        ->and($order->fresh()->metadata['activation_status'])->toBe(BillingActivationStatus::Failed->value)
        ->and(AuditLog::query()->where('action', 'billing.subscription.activation_failed')->count())->toBe(1);
});
