<?php

declare(strict_types=1);

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\PaymentTransaction;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\BillingPaymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('derives canonical payment status from the append-only ledger', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    PaymentTransaction::query()->create([
        'billing_order_id' => $order->getKey(), 'user_id' => $user->getKey(),
        'provider' => 'fake', 'type' => PaymentTransactionType::Authorization,
        'status' => PaymentTransactionStatus::Succeeded, 'amount_minor' => $order->total_minor,
        'currency' => $order->currency, 'provider_transaction_id' => 'fake_status_auth',
        'idempotency_key' => 'payment-status-auth',
    ]);

    expect(app(BillingPaymentStatusService::class)->project($order)['payment_status'])->toBe('authorized');
});
