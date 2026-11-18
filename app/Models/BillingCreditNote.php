<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditNoteStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Credit-note foundation. No refund or ledger mutation in Prompt 645.
 *
 * @property string $id
 * @property string|null $credit_note_number
 * @property string $billing_invoice_id
 * @property string $user_id
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property CreditNoteStatus $status
 * @property string|null $reason
 * @property Carbon|null $issued_at
 * @property array<string, mixed>|null $metadata
 */
class BillingCreditNote extends BaseModel
{
    protected $table = 'billing_credit_notes';

    /** @var list<string> */
    protected $fillable = [
        'credit_note_number',
        'billing_invoice_id',
        'user_id',
        'currency',
        'subtotal_minor',
        'tax_minor',
        'total_minor',
        'status',
        'reason',
        'issued_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => CreditNoteStatus::class,
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'issued_at' => 'datetime',
            'metadata' => 'array',
        ]);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
