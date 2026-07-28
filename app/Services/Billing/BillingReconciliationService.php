<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\BillingActivationStatus;
use App\Enums\BillingOrderStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BillingReconciliationService
{
    public function __construct(private readonly AuditLogWriter $audit) {}

    /** @return Collection<int, array<string, mixed>> */
    public function detectAnomalies(int $limit = 100): Collection
    {
        $findings = collect();
        $timeoutMinutes = max(1, (int) config('billing.processing_timeout_minutes', 15));

        BillingOrder::query()
            ->where('status', BillingOrderStatus::Paid)
            ->limit($limit)
            ->get()
            ->filter(function (BillingOrder $order): bool {
                $status = $order->metadata['activation_status'] ?? BillingActivationStatus::Pending->value;

                return $order->subscription_id === null || $status !== BillingActivationStatus::Succeeded->value;
            })
            ->each(function (BillingOrder $order) use ($findings): void {
                $findings->push([
                    'type' => 'paid_order_inactive_subscription',
                    'billing_order_id' => $order->getKey(),
                ]);
            });

        BillingOrder::query()
            ->where('status', BillingOrderStatus::Processing)
            ->where('updated_at', '<', now()->subMinutes($timeoutMinutes))
            ->limit($limit)
            ->get()
            ->each(function (BillingOrder $order) use ($findings): void {
                $findings->push([
                    'type' => 'processing_order_stuck',
                    'billing_order_id' => $order->getKey(),
                ]);
            });

        PaymentTransaction::query()
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->where('type', PaymentTransactionType::Sale)
            ->limit($limit)
            ->get()
            ->each(function (PaymentTransaction $transaction) use ($findings): void {
                $order = $transaction->billingOrder;
                if ($order instanceof BillingOrder && $order->status !== BillingOrderStatus::Paid) {
                    $findings->push([
                        'type' => 'succeeded_transaction_unpaid_order',
                        'billing_order_id' => $order->getKey(),
                        'payment_transaction_id' => $transaction->getKey(),
                    ]);
                }
            });

        return $findings->unique(fn (array $item): string => ($item['type'] ?? '').':'.($item['billing_order_id'] ?? ''))->values();
    }

    public function markReconciliationRequired(BillingOrder $order, string $reason): void
    {
        DB::transaction(function () use ($order, $reason): void {
            $locked = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $metadata = $locked->metadata ?? [];
            $metadata['activation_status'] = BillingActivationStatus::ReconciliationRequired->value;
            $metadata['reconciliation_reason'] = $reason;
            $locked->forceFill(['metadata' => $metadata])->save();

            $this->audit->write('billing.reconciliation.required', $locked->user_id, $locked, null, [
                'reason' => $reason,
            ]);
        });
    }
}
