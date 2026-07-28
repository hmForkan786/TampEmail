<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable invoice line item. Totals are never recalculated after issue.
 *
 * @property string $id
 * @property string $billing_invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property int $position
 * @property array<string, mixed>|null $metadata
 */
class BillingInvoiceLineItem extends BaseModel
{
    protected $table = 'billing_invoice_line_items';

    /** @var list<string> */
    protected $fillable = [
        'billing_invoice_id',
        'description',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'position',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'position' => 'integer',
            'metadata' => 'array',
        ]);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'billing_invoice_id');
    }
}
