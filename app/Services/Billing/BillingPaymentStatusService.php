<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\BillingOrder;
use App\Models\PaymentSettlement;

final class BillingPaymentStatusService
{
    /** @return array{payment_status:string,settlement_status:?string,paid_minor:int,authorized_minor:int,refunded_minor:int,last_payment_attempt_at:?string} */
    public function project(BillingOrder $order): array
    {
        $transactions = $order->paymentTransactions()->get();
        $succeeded = $transactions->where('status', PaymentTransactionStatus::Succeeded);
        $paid = $succeeded->whereIn('type', [PaymentTransactionType::Sale, PaymentTransactionType::Capture])->sum('amount_minor');
        $authorized = $succeeded->where('type', PaymentTransactionType::Authorization)->sum('amount_minor');
        $refunded = $succeeded->whereIn('type', [PaymentTransactionType::Refund, PaymentTransactionType::PartialRefund])->sum('amount_minor');
        $status = match (true) {
            $succeeded->where('type', PaymentTransactionType::Chargeback)->isNotEmpty() => 'charged_back',
            $refunded >= $paid && $paid > 0 => 'refunded',
            $refunded > 0 => 'partially_refunded',
            $paid >= $order->total_minor => 'paid',
            $paid > 0 => 'partially_paid',
            $authorized > 0 => 'authorized',
            $transactions->where('status', PaymentTransactionStatus::Failed)->isNotEmpty() => 'payment_failed',
            $transactions->where('status', PaymentTransactionStatus::Pending)->isNotEmpty() => 'pending',
            default => 'unpaid',
        };

        return [
            'payment_status' => $status,
            'settlement_status' => PaymentSettlement::query()->where('billing_order_id', $order->getKey())->latest()->first()?->status->value,
            'paid_minor' => (int) $paid,
            'authorized_minor' => (int) $authorized,
            'refunded_minor' => (int) $refunded,
            'last_payment_attempt_at' => $transactions->max('created_at')?->toIso8601String(),
        ];
    }
}
