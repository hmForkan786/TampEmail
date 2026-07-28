<?php

declare(strict_types=1);

namespace App\Services\Billing\Invoice;

use App\Enums\BillingOrderType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentTransactionType;
use App\Exceptions\Billing\InvoiceException;
use App\Models\BillingInvoice;
use App\Models\BillingInvoiceLineItem;
use App\Models\BillingOrder;
use App\Models\PaymentTransaction;
use App\Services\Audit\AuditLogWriter;
use App\Services\Billing\StateMachines\InvoiceStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Provider-neutral invoice lifecycle. Invoices are records, never payment authority.
 */
final class InvoiceService
{
    public function __construct(
        private readonly InvoiceNumberAllocator $numbers,
        private readonly InvoiceStateMachine $stateMachine,
        private readonly AuditLogWriter $audit,
    ) {}

    /**
     * Create, issue, and mark paid from a verified paid order (Prompt 638 path only).
     */
    public function issuePaidFromOrder(BillingOrder $order, ?PaymentTransaction $transaction = null): BillingInvoice
    {
        $existing = BillingInvoice::query()->where('billing_order_id', $order->getKey())->first();
        if ($existing instanceof BillingInvoice) {
            if ($existing->status === InvoiceStatus::Paid) {
                return $existing;
            }

            return $this->markPaid($existing, $transaction);
        }

        return DB::transaction(function () use ($order, $transaction): BillingInvoice {
            $lockedOrder = BillingOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $duplicate = BillingInvoice::query()->where('billing_order_id', $lockedOrder->getKey())->lockForUpdate()->first();
            if ($duplicate instanceof BillingInvoice) {
                return $duplicate->status === InvoiceStatus::Paid
                    ? $duplicate
                    : $this->markPaid($duplicate, $transaction);
            }

            $this->assertLedgerConsistency($lockedOrder, $transaction);
            $lockedOrder->loadMissing(['plan', 'user']);
            $customer = trim((string) ($lockedOrder->user->name ?? ''));
            if ($customer === '') {
                $customer = (string) ($lockedOrder->user->email ?? 'Customer');
            }

            $invoice = BillingInvoice::query()->create([
                'billing_order_id' => $lockedOrder->getKey(),
                'subscription_id' => $lockedOrder->subscription_id,
                'user_id' => $lockedOrder->user_id,
                'currency' => $lockedOrder->currency,
                'subtotal_minor' => $lockedOrder->subtotal_minor,
                'tax_minor' => $lockedOrder->tax_minor,
                'discount_minor' => $lockedOrder->discount_minor,
                'total_minor' => $lockedOrder->total_minor,
                'status' => InvoiceStatus::Draft,
                'provider' => $lockedOrder->provider ?? $transaction?->provider,
                'provider_reference' => $lockedOrder->provider_reference ?? $transaction?->provider_transaction_id,
                'metadata' => [
                    'order_type' => $lockedOrder->type->value,
                    'recovery' => $this->isRecovery($lockedOrder),
                    'plan_id' => $lockedOrder->plan_id,
                    'customer_label' => $customer,
                ],
            ]);

            $this->audit->write('invoice_created', $lockedOrder->user_id, $invoice, null, [
                'billing_order_id' => $lockedOrder->getKey(),
            ]);

            $this->createLineItems($invoice, $lockedOrder);
            $issued = $this->issue($invoice);
            $paid = $this->markPaid($issued, $transaction);

            return $paid->load('lineItems');
        });
    }

    public function issue(BillingInvoice $invoice): BillingInvoice
    {
        return DB::transaction(function () use ($invoice): BillingInvoice {
            $locked = BillingInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === InvoiceStatus::Issued || $locked->status === InvoiceStatus::Paid) {
                return $locked;
            }

            $this->stateMachine->assertCanTransition($locked->status, InvoiceStatus::Issued);
            $number = $locked->invoice_number ?? $this->numbers->allocate();
            $locked->forceFill([
                'invoice_number' => $number,
                'status' => InvoiceStatus::Issued,
                'issued_at' => $locked->issued_at ?? now(),
            ])->save();

            $this->audit->write('invoice_issued', $locked->user_id, $locked, null, [
                'invoice_number' => $number,
            ]);

            return $locked->fresh(['lineItems']);
        });
    }

    public function markPaid(BillingInvoice $invoice, ?PaymentTransaction $transaction = null): BillingInvoice
    {
        return DB::transaction(function () use ($invoice, $transaction): BillingInvoice {
            $locked = BillingInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === InvoiceStatus::Paid) {
                return $locked;
            }

            if ($locked->status === InvoiceStatus::Draft) {
                $locked = $this->issue($locked);
                $locked = BillingInvoice::query()->whereKey($locked->getKey())->lockForUpdate()->firstOrFail();
            }

            $this->stateMachine->assertCanTransition($locked->status, InvoiceStatus::Paid);
            $order = BillingOrder::query()->whereKey($locked->billing_order_id)->firstOrFail();
            $this->assertLedgerConsistency($order, $transaction);

            $locked->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_at' => $order->paid_at ?? now(),
                'provider' => $locked->provider ?? $order->provider ?? $transaction?->provider,
                'provider_reference' => $locked->provider_reference
                    ?? $order->provider_reference
                    ?? $transaction?->provider_transaction_id,
            ])->save();

            $this->audit->write('invoice_paid', $locked->user_id, $locked, null, [
                'invoice_number' => $locked->invoice_number,
                'provider_reference' => $locked->provider_reference,
            ]);

            return $locked->fresh(['lineItems']);
        });
    }

    public function void(BillingInvoice $invoice, ?string $actorUserId = null): BillingInvoice
    {
        return DB::transaction(function () use ($invoice, $actorUserId): BillingInvoice {
            $locked = BillingInvoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->status->allowsVoid()) {
                throw InvoiceException::invalidTransition('Paid invoices cannot be voided.');
            }

            $this->stateMachine->assertCanTransition($locked->status, InvoiceStatus::Void);
            $locked->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
            ])->save();

            $this->audit->write('invoice_voided', $actorUserId ?? $locked->user_id, $locked, null, [
                'invoice_number' => $locked->invoice_number,
            ]);

            return $locked->fresh(['lineItems']);
        });
    }

    public function assertLedgerConsistency(BillingOrder $order, ?PaymentTransaction $transaction = null): void
    {
        $ledgerTotal = (int) PaymentTransaction::query()
            ->where('billing_order_id', $order->getKey())
            ->where('status', PaymentTransactionStatus::Succeeded)
            ->whereIn('type', [
                PaymentTransactionType::Sale,
                PaymentTransactionType::Capture,
            ])
            ->sum('amount_minor');

        if ($ledgerTotal < 1 && $transaction instanceof PaymentTransaction
            && $transaction->status === PaymentTransactionStatus::Succeeded) {
            $ledgerTotal = $transaction->amount_minor;
        }

        if ($ledgerTotal !== (int) $order->total_minor) {
            throw InvoiceException::ledgerMismatch('Invoice totals must equal ledger totals.', [
                'order_total_minor' => $order->total_minor,
                'ledger_total_minor' => $ledgerTotal,
            ]);
        }

        if ($transaction instanceof PaymentTransaction) {
            if ($transaction->currency !== $order->currency) {
                throw InvoiceException::ledgerMismatch('Invoice currency must equal ledger currency.', [
                    'order_currency' => $order->currency,
                    'ledger_currency' => $transaction->currency,
                ]);
            }
        }
    }

    private function createLineItems(BillingInvoice $invoice, BillingOrder $order): void
    {
        $order->loadMissing('plan');
        $cycle = (string) (($order->metadata ?? [])['billing_cycle'] ?? 'monthly');
        $description = sprintf(
            '%s — %s (%s)',
            $order->plan->name ?? 'Plan',
            ucfirst($order->type->value),
            $cycle,
        );

        $unit = max(0, $order->subtotal_minor);
        BillingInvoiceLineItem::query()->create([
            'billing_invoice_id' => $invoice->getKey(),
            'description' => $description,
            'quantity' => 1,
            'unit_price_minor' => $unit,
            'line_total_minor' => $unit,
            'position' => 0,
            'metadata' => [
                'plan_slug' => ($order->metadata ?? [])['plan_slug'] ?? null,
                'billing_cycle' => $cycle,
            ],
        ]);

        if ($order->discount_minor > 0) {
            BillingInvoiceLineItem::query()->create([
                'billing_invoice_id' => $invoice->getKey(),
                'description' => 'Discount',
                'quantity' => 1,
                'unit_price_minor' => 0,
                'line_total_minor' => 0,
                'position' => 1,
                'metadata' => ['discount_minor' => $order->discount_minor],
            ]);
        }
    }

    private function isRecovery(BillingOrder $order): bool
    {
        return $order->type === BillingOrderType::Renewal
            && (bool) (($order->metadata ?? [])['recovery'] ?? false);
    }
}
