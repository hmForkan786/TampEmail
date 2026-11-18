<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DTOs\Billing\RecordPaymentTransactionData;
use App\DTOs\Billing\RefundPaymentData;
use App\DTOs\Billing\RefundResult;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\BillingException;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\StateMachines\BillingOrderStateMachine;
use Illuminate\Support\Facades\DB;

final class BillingRefundService
{
    public function __construct(
        private readonly BillingOrderStateMachine $orderStateMachine,
        private readonly BillingEntitlementImpactService $entitlementImpact,
        private readonly AuditLogWriter $audit,
    ) {}

    public function recordRefund(BillingOrder $order, RefundPaymentData $data, RefundResult $result): PaymentTransaction
    {
        return DB::transaction(function () use ($order, $data, $result): PaymentTransaction {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== BillingOrderStatus::Paid && $locked->status !== BillingOrderStatus::PartiallyRefunded) {
                throw new BillingException('Only paid orders can be refunded.');
            }

            $captured = $this->capturedAmountMinor($locked);
            $refunded = $this->refundedAmountMinor($locked);

            if ($refunded + $data->amountMinor > $captured) {
                throw new BillingException('Cumulative refund exceeds captured amount.');
            }

            $transaction = PaymentTransaction::query()->create([
                'billing_order_id' => $locked->getKey(),
                'user_id' => $locked->user_id,
                'provider' => $data->provider,
                'type' => $data->partial ? PaymentTransactionType::PartialRefund : PaymentTransactionType::Refund,
                'status' => $result->succeeded ? PaymentTransactionStatus::Succeeded : PaymentTransactionStatus::Failed,
                'amount_minor' => $data->amountMinor,
                'currency' => strtoupper($data->currency),
                'provider_transaction_id' => $result->providerRefundId,
                'idempotency_key' => $data->idempotencyKey,
                'processed_at' => $result->succeeded ? now() : null,
            ]);

            if ($result->succeeded) {
                $newRefunded = $refunded + $data->amountMinor;
                $targetStatus = $newRefunded >= $captured
                    ? BillingOrderStatus::Refunded
                    : BillingOrderStatus::PartiallyRefunded;

                $this->orderStateMachine->assertCanTransition($locked->status, $targetStatus);
                $locked->forceFill(['status' => $targetStatus])->save();

                if ($targetStatus === BillingOrderStatus::Refunded) {
                    $this->entitlementImpact->applyFullRefund($locked);
                }

                $this->audit->write('billing.refund.succeeded', $locked->user_id, $locked, null, [
                    'amount_minor' => $data->amountMinor,
                    'partial' => $data->partial,
                ]);
            }

            return $transaction;
        });
    }

    public function recordChargeback(BillingOrder $order, RecordPaymentTransactionData $data): PaymentTransaction
    {
        return DB::transaction(function () use ($order, $data): PaymentTransaction {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $this->orderStateMachine->assertCanTransition($locked->status, BillingOrderStatus::ChargedBack);

            $transaction = PaymentTransaction::query()->create([
                'billing_order_id' => $locked->getKey(),
                'user_id' => $locked->user_id,
                'provider' => $data->provider,
                'type' => PaymentTransactionType::Chargeback,
                'status' => PaymentTransactionStatus::Succeeded,
                'amount_minor' => $data->amountMinor,
                'currency' => strtoupper($data->currency),
                'provider_transaction_id' => $data->providerTransactionId,
                'provider_event_id' => $data->providerEventId,
                'idempotency_key' => $data->idempotencyKey,
                'processed_at' => now(),
            ]);

            $locked->forceFill(['status' => BillingOrderStatus::ChargedBack])->save();
            $this->entitlementImpact->applyChargeback($locked);

            $this->audit->write('billing.chargeback.received', $locked->user_id, $locked, null, [
                'amount_minor' => $data->amountMinor,
            ]);

            return $transaction;
        });
    }

    private function capturedAmountMinor(BillingOrder $order): int
    {
        return (int) PaymentTransaction::query()
            ->where('billing_order_id', $order->getKey())
            ->where('type', PaymentTransactionType::Sale)
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->sum('amount_minor');
    }

    private function refundedAmountMinor(BillingOrder $order): int
    {
        return (int) PaymentTransaction::query()
            ->where('billing_order_id', $order->getKey())
            ->whereIn('type', [PaymentTransactionType::Refund, PaymentTransactionType::PartialRefund])
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->sum('amount_minor');
    }
}
