<?php

declare(strict_types=1);

namespace App\Services\Billing\Invoice;

use App\Enums\CreditNoteStatus;
use App\Exceptions\Billing\InvoiceException;
use App\Models\BillingCreditNote;
use App\Models\BillingInvoice;
use App\Services\Audit\AuditLogWriter;
use Illuminate\Support\Facades\DB;

/**
 * Credit-note foundation. No refunds or ledger mutations.
 */
final class CreditNoteService
{
    public function __construct(
        private readonly AuditLogWriter $audit,
    ) {}

    public function draft(BillingInvoice $invoice, int $totalMinor, ?string $reason = null): BillingCreditNote
    {
        if ($totalMinor < 1 || $totalMinor > $invoice->total_minor) {
            throw InvoiceException::invalidTransition('Credit note amount is invalid.');
        }

        return DB::transaction(function () use ($invoice, $totalMinor, $reason): BillingCreditNote {
            $note = BillingCreditNote::query()->create([
                'billing_invoice_id' => $invoice->getKey(),
                'user_id' => $invoice->user_id,
                'currency' => $invoice->currency,
                'subtotal_minor' => $totalMinor,
                'tax_minor' => 0,
                'total_minor' => $totalMinor,
                'status' => CreditNoteStatus::Draft,
                'reason' => $reason,
                'metadata' => ['financial_mutation' => false],
            ]);

            $this->audit->write('credit_note_created', $invoice->user_id, $note, null, [
                'billing_invoice_id' => $invoice->getKey(),
            ]);

            return $note;
        });
    }

    public function issue(BillingCreditNote $note): BillingCreditNote
    {
        return DB::transaction(function () use ($note): BillingCreditNote {
            $locked = BillingCreditNote::query()->whereKey($note->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === CreditNoteStatus::Issued) {
                return $locked;
            }

            $locked->forceFill([
                'status' => CreditNoteStatus::Issued,
                'issued_at' => now(),
                'credit_note_number' => $locked->credit_note_number ?? sprintf(
                    'CN-%s-%s',
                    now()->format('Y'),
                    strtoupper(substr(str_replace('-', '', (string) $locked->getKey()), 0, 8)),
                ),
            ])->save();

            $this->audit->write('credit_note_issued', $locked->user_id, $locked, null, [
                'credit_note_number' => $locked->credit_note_number,
                'financial_mutation' => false,
            ]);

            return $locked->fresh();
        });
    }
}
