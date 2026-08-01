<?php

declare(strict_types=1);

use App\Enums\PaymentSettlementStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\InvalidBillingStateTransitionException;
use App\Models\PaymentSettlement;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\BillingPaymentStatusService;
use App\Services\Billing\PaymentSettlementService;
use App\Services\Billing\PaymentStatusSynchronizationService;
use App\Services\Billing\StateMachines\PaymentSettlementStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('persists settlement components and enforces transitions', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $transaction = PaymentTransaction::query()->create([
        'billing_order_id' => $order->getKey(), 'user_id' => $user->getKey(), 'provider' => 'fake',
        'type' => PaymentTransactionType::Sale, 'status' => PaymentTransactionStatus::Succeeded,
        'amount_minor' => $order->total_minor, 'currency' => $order->currency,
        'provider_transaction_id' => 'fake_settlement_tx', 'idempotency_key' => 'settlement-ledger',
    ]);
    $service = app(PaymentSettlementService::class);
    $settlement = $service->record($transaction, PaymentSettlementStatus::Pending, 'fake_stl_1', 900, 'USD', 50, 0, 850);
    $settled = $service->record($transaction, PaymentSettlementStatus::Settled, 'fake_stl_1', 900, 'USD', 50, 0, 850);

    expect($settled->status)->toBe(PaymentSettlementStatus::Settled)
        ->and(PaymentSettlement::query()->count())->toBe(1)
        ->and(fn () => app(PaymentSettlementStateMachine::class)->assertCanTransition(PaymentSettlementStatus::Settled, PaymentSettlementStatus::Pending))
        ->toThrow(InvalidBillingStateTransitionException::class);
});

it('projects ledger state and synchronizes fake query results idempotently', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    $order->forceFill(['status' => 'processing', 'provider' => 'fake', 'provider_reference' => 'fake_success_sync'])->save();
    $sync = app(PaymentStatusSynchronizationService::class);

    $sync->sync($order->fresh());
    $sync->sync($order->fresh());
    $projection = app(BillingPaymentStatusService::class)->project($order->fresh());

    expect($projection['payment_status'])->toBe('paid')
        ->and($projection['paid_minor'])->toBe($order->total_minor)
        ->and(PaymentTransaction::query()->where('status', PaymentTransactionStatus::Succeeded)->count())->toBe(1);
});
