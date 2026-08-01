<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PaymentSettlementStatus;
use App\Exceptions\Billing\PaymentVerificationException;
use App\Models\PaymentSettlement;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\StateMachines\PaymentSettlementStateMachine;
use Illuminate\Support\Facades\DB;

final class PaymentSettlementService
{
    public function __construct(
        private readonly PaymentSettlementStateMachine $states,
        private readonly AuditLogWriter $audit,
    ) {}

    public function record(
        PaymentTransaction $transaction,
        PaymentSettlementStatus $status,
        ?string $reference,
        int $grossMinor,
        string $currency,
        ?int $feeMinor = null,
        ?int $taxMinor = null,
        ?int $netMinor = null,
    ): PaymentSettlement {
        if ($grossMinor !== $transaction->amount_minor || strtoupper($currency) !== $transaction->currency) {
            throw new PaymentVerificationException('Settlement amount or currency mismatch.');
        }
        if ($feeMinor !== null && $taxMinor !== null && $netMinor !== null && $feeMinor + $taxMinor + $netMinor !== $grossMinor) {
            throw new PaymentVerificationException('Settlement components do not equal gross amount.');
        }

        return DB::transaction(function () use ($transaction, $status, $reference, $grossMinor, $currency, $feeMinor, $taxMinor, $netMinor): PaymentSettlement {
            $settlement = PaymentSettlement::query()->where('provider', $transaction->provider)
                ->where('provider_settlement_id', $reference)->lockForUpdate()->first();
            if ($settlement instanceof PaymentSettlement) {
                if ($settlement->payment_transaction_id !== $transaction->getKey()) {
                    throw new PaymentVerificationException('Settlement reference belongs to another transaction.');
                }
                $this->states->assertCanTransition($settlement->status, $status);
                $settlement->forceFill([
                    'status' => $status,
                    'settled_at' => $status === PaymentSettlementStatus::Settled ? now() : $settlement->settled_at,
                    'failed_at' => $status === PaymentSettlementStatus::Failed ? now() : null,
                ])->save();
            } else {
                $settlement = PaymentSettlement::query()->create([
                    'payment_transaction_id' => $transaction->getKey(),
                    'billing_order_id' => $transaction->billing_order_id,
                    'provider' => $transaction->provider,
                    'provider_settlement_id' => $reference,
                    'status' => $status,
                    'gross_amount_minor' => $grossMinor,
                    'fee_amount_minor' => $feeMinor,
                    'tax_amount_minor' => $taxMinor,
                    'net_amount_minor' => $netMinor,
                    'currency' => strtoupper($currency),
                    'settled_at' => $status === PaymentSettlementStatus::Settled ? now() : null,
                    'failed_at' => $status === PaymentSettlementStatus::Failed ? now() : null,
                ]);
            }
            $this->audit->write('billing.settlement.'.$status->value, $transaction->user_id, $settlement, null, [
                'order_id' => $transaction->billing_order_id, 'transaction_id' => $transaction->getKey(),
                'settlement_id' => $settlement->getKey(), 'status' => $status->value,
            ]);

            return $settlement->fresh();
        });
    }
}
