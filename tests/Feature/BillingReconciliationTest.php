<?php

use App\Enums\BillingActivationStatus;
use App\Enums\BillingOrderStatus;
use App\Models\AuditLog;
use App\Services\Billing\BillingOrderService;
use App\Services\Billing\BillingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/billing_helpers.php';

it('detects paid orders without successful activation', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(BillingOrderService::class)->transition($order, BillingOrderStatus::Processing);
    app(BillingOrderService::class)->transition($order->fresh(), BillingOrderStatus::Paid, ['paid_at' => now()]);

    $findings = app(BillingReconciliationService::class)->detectAnomalies();
    expect($findings->pluck('type'))->toContain('paid_order_inactive_subscription');
});

it('marks reconciliation metadata and audits the requirement', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(BillingReconciliationService::class)->markReconciliationRequired($order, 'manual_review');

    expect($order->fresh()->metadata['activation_status'])->toBe(BillingActivationStatus::ReconciliationRequired->value)
        ->and(AuditLog::query()->where('action', 'billing.reconciliation.required')->count())->toBe(1);
});

it('runs the reconcile command in dry-run mode', function (): void {
    [$user, $plan] = billingPremiumContext();
    $order = app(BillingOrderService::class)->create(billingOrderData($user, $plan));
    app(BillingOrderService::class)->transition($order, BillingOrderStatus::Processing);
    app(BillingOrderService::class)->transition($order->fresh(), BillingOrderStatus::Paid, ['paid_at' => now()]);

    $this->artisan('billing:reconcile --dry-run')->assertSuccessful();
});
